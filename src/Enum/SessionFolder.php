<?php

namespace App\Enum;

enum SessionFolder: string
{
    case LIGHT    = 'LIGHT';
    case DARK     = 'DARK';
    case BIAS     = 'BIAS';
    case FLAT     = 'FLAT';
    case MASTER   = 'MASTER';
    case EXPORT   = 'EXPORT';
    case LOG_NINA = 'LOG_NINA';
    case LOG_PHD2 = 'LOG_PHD2';
    case LOG_WBPP = 'LOG_WBPP';
    case LOG_AF   = 'LOG_AF';
    case DOC      = 'DOC';

    public function defaultRelativePath(): string
    {
        return match ($this) {
            self::LIGHT    => '02 - Acquisition/raw/light',
            self::DARK     => '02 - Acquisition/raw/dark',
            self::BIAS     => '02 - Acquisition/raw/bias',
            self::FLAT     => '02 - Acquisition/raw/flat',
            self::MASTER   => '03 - Processing/master',
            self::EXPORT   => '03 - Processing/exports',
            self::LOG_NINA => '02 - Acquisition/logs/nina',
            self::LOG_PHD2 => '02 - Acquisition/logs/phd2',
            self::LOG_WBPP => '03 - Processing/logs',
            self::LOG_AF   => '02 - Acquisition/logs/autofocus',
            self::DOC      => '99 - Docs',
        };
    }

    /**
     * Regex pattern for Finder->name() matching supported file extensions.
     * Returns null if the folder has no scannable file pattern.
     */
    public function filePattern(): ?string
    {
        return match ($this) {
            self::LIGHT, self::DARK, self::BIAS, self::FLAT
                       => '/\.(fit|fits|nef|cr2|cr3|arw|orf|rw2|raf|dng|pef|srw|nrw)$/i',
            self::MASTER   => '/\.(xisf|fits)$/i',
            self::EXPORT   => '/\.(jpg|jpeg|png|tif|tiff)$/i',
            self::LOG_PHD2 => '/\.(txt)$/i',
            self::LOG_WBPP => '/\.(log)$/i',
            default        => null,
        };
    }

    /**
     * All raw-type folders (contain Exposure entities).
     * @return self[]
     */
    public static function rawFolders(): array
    {
        return [self::LIGHT, self::DARK, self::FLAT, self::BIAS];
    }
}
