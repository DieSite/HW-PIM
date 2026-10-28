<?php

namespace App\Exceptions;

class ProductHasNoImagesException extends \Exception
{
    public function __construct(string $sku)
    {
        parent::__construct("Product '$sku' heeft geen afbeeldingen en er is geen standaardafbeelding ingesteld. Voeg een afbeelding toe aan het product, of stel onder Configuratie de 'Geen afbeelding beschikbaar'-afbeelding in, en synchroniseer opnieuw.");
    }
}
