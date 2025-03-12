<?php

namespace Webkul\WooCommerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\WooCommerce\Models\Credential;

class CredentialFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Credential::class;

    /**
     * Define the model's default state.
     * Fake credentials are used for the testing purposes.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'shopUrl'            => 'http://192.168.15.111/wordpress/shop',
            'consumerKey'        => 'ck_1673721e36c80f0b1e3dc0a17adf10dd595e4bd8',
            'consumerSecret'     => 'cs_7dafd1c4e746598e2af6eb15982036239e9e8ecf',
        ];
    }
}
