<?php

namespace Spokospace\OpsNotify\Support;

use RuntimeException;

/**
 * Preset bot avatars shipped with the package (resources/avatars, 640x640 JPEG, the size
 * Telegram recommends) and conversion of uploaded images to the JPEG Telegram requires.
 */
final class BotAvatars
{
    public const SIZE = 640;

    /** Picker value for "use an uploaded file" instead of a preset. */
    public const CUSTOM = 'custom';

    /** @var list<string>|null */
    private static ?array $keys = null;

    /** @return list<string> Preset keys, e.g. "mascot-1". Read from disk once per process. */
    public static function keys(): array
    {
        return self::$keys ??= array_map(
            fn (string $path): string => basename($path, '.jpg'),
            glob(self::dir().'/*.jpg') ?: [],
        );
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    public static function path(string $key, bool $thumbnail = false): string
    {
        if (! self::exists($key)) {
            throw new RuntimeException("Unknown avatar preset [{$key}].");
        }

        return self::dir().($thumbnail ? '/thumbs/' : '/').$key.'.jpg';
    }

    /**
     * Any image the user uploaded (JPEG, PNG, WebP), as a square JPEG. Uses GD; JPEG passes
     * through untouched when GD is missing.
     */
    public static function toJpeg(string $contents): string
    {
        $isJpeg = str_starts_with($contents, "\xFF\xD8\xFF");

        if (! function_exists('imagecreatefromstring')) {
            return $isJpeg ? $contents : throw new RuntimeException('Only JPEG images can be used without the PHP GD extension.');
        }

        $source = @imagecreatefromstring($contents) ?: throw new RuntimeException('The file is not a supported image.');
        $side = min(imagesx($source), imagesy($source));

        // Centre-crop to a square on a white background (PNG transparency has no JPEG equivalent).
        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255) ?: throw new RuntimeException('Could not allocate a colour for the avatar background.'));
        imagecopyresampled(
            $target, $source, 0, 0,
            intdiv(imagesx($source) - $side, 2), intdiv(imagesy($source) - $side, 2),
            self::SIZE, self::SIZE, $side, $side,
        );

        ob_start();
        imagejpeg($target, null, 90);

        return (string) ob_get_clean();
    }

    private static function dir(): string
    {
        return dirname(__DIR__, 2).'/resources/avatars';
    }
}
