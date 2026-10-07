<?php

namespace App\Services\Receipts;

use Imagick;
use Spatie\Browsershot\Browsershot;

/** Turns a photographed receipt into a one-page PDF; the bookkeeping folder only holds PDFs. */
class ReceiptImageConverter
{
    private const MAX_SIDE = 2400;

    public function toPdf(string $imageBytes): string
    {
        [$jpeg, $width, $height] = $this->normalise($imageBytes);

        // Page sized to the image (96 CSS px per inch), capped at A4 width so a phone photo
        // prints sensibly. ImageMagick's own PDF writer is often disabled by server policy.
        $pageWidthMm = 210;
        $pageHeightMm = max(20, (int) ceil($pageWidthMm * $height / $width));
        $html = '<!doctype html><html><head><style>'
            .'@page{margin:0}html,body{margin:0;padding:0}img{display:block;width:100%;height:auto}'
            .'</style></head><body><img src="data:image/jpeg;base64,'.base64_encode($jpeg).'"></body></html>';

        $shot = Browsershot::html($html)
            ->paperSize($pageWidthMm, $pageHeightMm)
            ->margins(0, 0, 0, 0)
            ->showBackground()
            ->noSandbox();
        if ($path = config('services.browsershot.chrome_path')) {
            $shot->setChromePath($path);
        }

        return $shot->pdf();
    }

    /**
     * Upright, metadata stripped, at most MAX_SIDE on the long side, JPEG.
     *
     * @return array{0: string, 1: int, 2: int} bytes, width, height
     */
    public function normalise(string $imageBytes): array
    {
        $image = new Imagick;
        $image->readImageBlob($imageBytes);
        $image->setIteratorIndex(0);
        $image->autoOrient();
        $image->setImageBackgroundColor('white');
        $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);

        if (max($image->getImageWidth(), $image->getImageHeight()) > self::MAX_SIDE) {
            $image->thumbnailImage(self::MAX_SIDE, self::MAX_SIDE, true);
        }
        $image->stripImage();
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(85);

        $result = [$image->getImageBlob(), $image->getImageWidth(), $image->getImageHeight()];
        $image->clear();

        return $result;
    }
}
