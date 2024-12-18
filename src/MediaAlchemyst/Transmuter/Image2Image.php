<?php

/*
 * This file is part of Media-Alchemyst.
 *
 * (c) Alchemy <dev.team@alchemy.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MediaAlchemyst\Transmuter;

use Imagine\Exception\Exception as ImagineException;
use Imagine\Image\AbstractImage;
use Imagine\Image\AbstractImagine;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\RGB;
use MediaVorus\Exception\ExceptionInterface as MediaVorusException;
use MediaAlchemyst\Specification\Image;
use MediaAlchemyst\Specification\SpecificationInterface;
use MediaAlchemyst\Exception\SpecNotSupportedException;
use MediaAlchemyst\Exception\RuntimeException;
use MediaVorus\Media\MediaInterface;
use Neutron\TemporaryFilesystem\Manager;
use PHPExiftool\Exception\ExceptionInterface as PHPExiftoolException;
use Pimple;

class Image2Image extends AbstractTransmuter
{
    public static $autorotate = true;
    public static $lookForEmbeddedPreview = false;

    private $palette;

    public function __construct(Pimple $container, Manager $manager)
    {
        parent::__construct($container, $manager);
        $this->palette = new RGB();
    }

    public function execute(SpecificationInterface $spec, MediaInterface $source, $dest)
    {
        if (! $spec instanceof Image) {
            throw new SpecNotSupportedException('Imagine Adapter only supports Image specs');
        }

        if ($source->getType() !== MediaInterface::TYPE_IMAGE) {
            throw new SpecNotSupportedException('Imagine Adapter only supports Images');
        }

        try {
            if (static::$lookForEmbeddedPreview) {
                $tmpFile = $this->extractEmbeddedImage($source->getFile()->getPathname());

                if ($tmpFile instanceof MediaInterface) {
                    $source = $tmpFile;
                }
            }

            if ($source->getFile()->getMimeType() === 'application/illustrator') {
                $tmpFile = $this->tmpFileManager->createTemporaryFile(self::TMP_FILE_SCOPE, 'gs_transcoder');

                $this->container['ghostscript.transcoder']->toImage(
                    $source->getFile()->getRealPath(), $tmpFile
                );

                if (file_exists($tmpFile)) {
                    $source = $this->container['mediavorus']->guess($tmpFile);
                }
            }
            elseif ($source->getFile()->getMimeType() === 'image/tiff') {

                /** @var AbstractImagine $imagine */
                $imagine = $this->container['imagine'];
                $image = $imagine->open($source->getFile()->getRealPath());


                // find the biggest layer by w*h, keep index(es) of each layer by size
                $layersBySize = array();
                $maxLayerSize = 0;
                $layers = $image->layers();
                /** @var ImageInterface $layer */
                foreach ($layers as $i=>$layer) {
                    $sizeKey = '_' . ($layerSize = $layer->getSize()->getWidth() * $layer->getSize()->getHeight());
                    if($layerSize > $maxLayerSize) {
                        $maxLayerSize = $layerSize;
                    }
                    if(!array_key_exists($sizeKey, $layersBySize)) {
                        $layersBySize[$sizeKey] = array();
                    }
                    $layersBySize[$sizeKey][] = $i;
                }

                // remove the smallest layers, then merge the biggest ones
                foreach ($layersBySize as $sizeKey => $indexes) {
                    if($sizeKey !== '_'.$maxLayerSize) {
                        foreach ($indexes as $i) {
                            $layers->remove($i);
                        }
                    }
                }

                try {
                    $image->layers()->merge();
                }
                catch (\Exception $e) {
                    // ignore: anyway we will use only the first layer
                }

                $tmpFile =  null;
                foreach ($image->layers() as $i=>$layer) {
                    // we should have only one layer after merge, but who knows ?
                    $tmpFile = $this->tmpFileManager->createTemporaryFile(self::TMP_FILE_SCOPE, 'imagine-tiff-layer', $source->getFile()->getExtension());
                    $layer->save($tmpFile);
                    break;      // only the first
                }

                unset($image);

                if($tmpFile) {
                    $source = $this->container['mediavorus']->guess($tmpFile);
                }
            }
            elseif (preg_match('#(application|image)\/([a-z0-9-_\.]*)photoshop([a-z0-9-_\.]*)#i', $source->getFile()->getMimeType())) {
                $image = $this->container['imagine']->open($source->getFile()->getRealPath());

                foreach ($image->layers() as $layer) {
                    $tmpFile = $this->tmpFileManager->createTemporaryFile(self::TMP_FILE_SCOPE, 'imagine-photoshop-layer', 'jpg');
                    $layer->save($tmpFile);
                    if (file_exists($tmpFile)) {
                        $source = $this->container['mediavorus']->guess($tmpFile);
                    }
                    break;
                }

                unset($image);
            }

            /** @var AbstractImage $image */
            $image = $this->container['imagine']->open($source->getFile()->getPathname());

            if ($spec->getWidth() && $spec->getHeight()) {
                $box = $this->boxFromSize($spec, $image->getSize()->getWidth(), $image->getSize()->getHeight());

                if (null !== $box) {
                    if ($spec->getResizeMode() == Image::RESIZE_MODE_OUTBOUND) {
                        /* @var $image \Imagine\Gmagick\Image */
                        $image = $image->thumbnail($box, ImageInterface::THUMBNAIL_OUTBOUND);
                    } else {
                        $image = $image->resize($box);
                    }
                }
            }

            if (static::$autorotate && null === $spec->getRotationAngle()) {
                $image = $image->rotate($source->getOrientation());
            } elseif (null !== $angle = $spec->getRotationAngle()) {
                $image = $image->rotate($angle);
            }

            $image->usePalette($this->palette);

            if (true == $spec->getStrip()) {
                $image = $image->strip();
            }

            $options = array(
//                'flatten'          => $spec->isFlatten() && strtolower(pathinfo($dest, PATHINFO_EXTENSION)) !== 'gif',
                'disable-alpha'    => $spec->isFlatten(),
                'quality'          => $spec->getQuality(),
                'resolution-units' => $spec->getResolutionUnit(),
                'resolution-x'     => $spec->getResolutionX(),
                'resolution-y'     => $spec->getResolutionY(),
                'background-color' => $spec->getBackgroundColor(),
            );

            $image->save($dest, $options);

            unset($image);

            $this->tmpFileManager->clean(self::TMP_FILE_SCOPE);
        }
        catch (MediaVorusException $e) {
            $this->tmpFileManager->clean(self::TMP_FILE_SCOPE);
            throw new RuntimeException('Unable to transmute image to image due to Mediavorus', null, $e);
        }
        catch (PHPExiftoolException $e) {
            $this->tmpFileManager->clean(self::TMP_FILE_SCOPE);
            throw new RuntimeException('Unable to transmute image to image due to PHPExiftool', null, $e);
        }
        catch (ImagineException $e) {
            $this->tmpFileManager->clean(self::TMP_FILE_SCOPE);
            throw new RuntimeException('Unable to transmute image to image due to Imagine', null, $e);
        }
        catch (RuntimeException $e) {
            $this->tmpFileManager->clean(self::TMP_FILE_SCOPE);
            throw $e;
        }
    }

    protected function extractEmbeddedImage($pathfile)
    {
        $tmpDir = $this->tmpFileManager->createTemporaryDirectory(0777, 500, self::TMP_FILE_SCOPE);

        $files = $this->container['exiftool.preview-extractor']->extract($pathfile, $tmpDir);

        $selected = null;
        $size = null;

        foreach ($files as $file) {
            if ($file->isDir() || $file->isDot()) {
                continue;
            }

            if (is_null($selected) || $file->getSize() > $size) {
                $selected = $file->getPathname();
                $size = $file->getSize();
            }
        }

        if ($selected) {
            return $this->container['mediavorus']->guess($selected);
        }

        return null;
    }
}
