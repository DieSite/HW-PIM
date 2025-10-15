<?php

namespace Webkul\Core\ImageCache;

use Intervention\Gif\Exceptions\NotReadableException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Exceptions\DriverException;
use Intervention\Image\Exceptions\InputException;
use Intervention\Image\Image;
use Intervention\Image\Interfaces\DecoderInterface;
use Intervention\Image\Interfaces\DriverInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Interfaces\ImageManagerInterface;

class ImageManager implements ImageManagerInterface
{
    private DriverInterface $driver;

    /**
     * @link https://image.intervention.io/v3/basics/configuration-drivers#create-a-new-image-manager-instance
     *
     * @throws DriverException
     * @throws InputException
     */
    public function __construct(string|DriverInterface $driver, mixed ...$options)
    {
        $this->driver = $this->resolveDriver($driver, ...$options);
    }

    /**
     * Create image manager with given driver
     *
     * @link https://image.intervention.io/v3/basics/configuration-drivers#static-constructor
     *
     * @throws DriverException
     * @throws InputException
     */
    public static function withDriver(string|DriverInterface $driver, mixed ...$options): self
    {
        return new self(self::resolveDriver($driver, ...$options));
    }

    /**
     * Create image manager with GD driver
     *
     * @link https://image.intervention.io/v3/basics/configuration-drivers#static-gd-driver-constructor
     *
     * @throws DriverException
     * @throws InputException
     */
    public static function gd(mixed ...$options): self
    {
        return self::withDriver(new GdDriver(), ...$options);
    }

    /**
     * Create image manager with Imagick driver
     *
     * @link https://image.intervention.io/v3/basics/configuration-drivers#static-imagick-driver-constructor
     *
     * @throws DriverException
     * @throws InputException
     */
    public static function imagick(mixed ...$options): self
    {
        return self::withDriver(new ImagickDriver(), ...$options);
    }

    /**
     * {@inheritdoc}
     *
     * @see ImageManagerInterface::create()
     */
    public function create(int $width, int $height): ImageInterface
    {
        return $this->driver->createImage($width, $height);
    }

    /**
     * {@inheritdoc}
     *
     * @see ImageManagerInterface::read()
     */
    public function read(mixed $input, string|array|DecoderInterface $decoders = []): ImageInterface
    {
        return $this->driver->handleInput(
            $input,
            match (true) {
                is_string($decoders), is_a($decoders, DecoderInterface::class) => [$decoders],
                default => $decoders,
            }
        );
    }

    /**
     * {@inheritdoc}
     *
     * @see ImageManagerInterface::animate()
     */
    public function animate(callable $init): ImageInterface
    {
        return $this->driver->createAnimation($init);
    }

    /**
     * {@inheritdoc}
     *
     * @see ImageManagerInterface::driver()
     */
    public function driver(): DriverInterface
    {
        return $this->driver;
    }

    /**
     * Initiates an Image instance from different input types
     *
     * @param  mixed  $data
     * @return Image
     */
    public function make($data, bool $can_be_url = true)
    {
        $driver = $this->driver();

        if ((bool) filter_var($data, FILTER_VALIDATE_URL) && $can_be_url) {
            return $this->initFromUrl($driver, $data);
        }

        return (new ImageManager($driver))->make($data);
    }

    /**
     * Init from given URL
     *
     * @param  DriverInterface  $driver
     * @param  string  $url
     * @return Image
     */
    public function initFromUrl($driver, $url)
    {
        $domain = config('app.url');

        $options = [
            'http' => [
                'method'           => 'GET',
                'protocol_version' => 1.1, // force use HTTP 1.1 for service mesh environment with envoy
                'header'           => "Accept-language: en\r\n".
                "Domain: $domain\r\n".
                "User-Agent: Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.71 Safari/537.36\r\n",
            ],
        ];

        $context = stream_context_create($options);

        if ($data = @file_get_contents($url, false, $context)) {
            return $this->make($data, false);
        }

        throw new NotReadableException(
            'Unable to init from given url ('.$url.').'
        );
    }

    /**
     * Return driver object from given input which might be driver classname or instance of DriverInterface
     *
     * @throws DriverException
     * @throws InputException
     */
    private static function resolveDriver(string|DriverInterface $driver, mixed ...$options): DriverInterface
    {
        $driver = match (true) {
            $driver instanceof DriverInterface => $driver,
            class_exists($driver)              => new $driver(),
            default                            => throw new DriverException(
                'Unable to resolve driver. Argment must be either an instance of '.
                DriverInterface::class.'::class or a qualified namespaced name of the driver class.',
            ),
        };

        if (! $driver instanceof DriverInterface) {
            throw new DriverException(
                'Unable to resolve driver. Driver object must implement '.DriverInterface::class.'.',
            );
        }

        $driver->config()->setOptions(...$options);

        return $driver;
    }
}
