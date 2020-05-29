<?php

declare(strict_types=1);

/*
 * This file is part of the Wonolog package.
 *
 * (c) Inpsyde GmbH
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Inpsyde\Wonolog\Handler;

use Inpsyde\Wonolog\LogLevel;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\NullHandler;

/**
 * Wonolog builds a default handler if no custom handler is provided.
 * This class has the responsibility to create an instance of this
 * default handler using sensitive defaults and allowing configuration
 * via hooks and environment variables.
 *
 * @package wonolog
 * @license http://opensource.org/licenses/MIT MIT
 */
class DefaultHandlerFactory
{
    public const FILTER_FOLDER = 'wonolog.default-handler-folder';
    public const FILTER_FILENAME = 'wonolog.default-handler-filename';
    public const FILTER_DATE_FORMAT = 'wonolog.default-handler-date-format';
    public const FILTER_BUBBLE = 'wonolog.default-handler-bubble';
    public const FILTER_USE_LOCKING = 'wonolog.default-handler-use-locking';

    /**
     * @var HandlerInterface
     */
    private $defaultHandler;

    /**
     * @param HandlerInterface|null $handler
     *
     * @return static
     */
    public static function new(?HandlerInterface $handler = null): DefaultHandlerFactory
    {
        return new static($handler);
    }

    /**
     * DefaultHandlerFactory constructor.
     *
     * @param HandlerInterface|null $handler
     */
    private function __construct(?HandlerInterface $handler = null)
    {
        $this->defaultHandler = $handler;
    }

    /**
     * @return HandlerInterface
     */
    public function createDefaultHandler(): HandlerInterface
    {
        if ($this->defaultHandler) {
            return $this->defaultHandler;
        }

        $this->defaultHandler = $this->createDefaultHandlerFromConfigs();

        return $this->defaultHandler;
    }

    /**
     * @return HandlerInterface
     */
    private function createDefaultHandlerFromConfigs(): HandlerInterface
    {
        $folder = $this->handlerFolder();

        if (! $folder) {
            return new NullHandler();
        }

        [$filenameFormat, $dateFormat] = $this->handlerFileInfo();

        $logLevel = LogLevel::instance();

        try {
            /**
             * Filters whether messages bubble up the stack.
             *
             * @param bool $bubble
             */
            $bubble = (bool) apply_filters(self::FILTER_BUBBLE, true);

            /**
             * Filters whether to try to lock the log file before writing.
             *
             * @param bool $useLocking
             */
            $useLocking = apply_filters(self::FILTER_USE_LOCKING, true);

            $handler = new DateBasedStreamHandler(
                "{$folder}/{$filenameFormat}",
                $dateFormat,
                $logLevel->defaultMinLevel(),
                $bubble,
                $useLocking
            );
        } catch (\Throwable $throwable) {
            $handler = new NullHandler();
        }

        return $handler;
    }

    /**
     * @return string
     */
    private function handlerFolder(): string
    {
        $folder = getenv('WONOLOG_DEFAULT_HANDLER_ROOT_DIR');

        if (! $folder && defined('WP_CONTENT_DIR')) {
            $folder = rtrim(WP_CONTENT_DIR, '\\/') . '/wonolog';
        }

        /**
         * Filters the handler folder to use.
         *
         * @param string $folder
         */
        $folder = apply_filters(self::FILTER_FOLDER, $folder);
        is_string($folder) or $folder = '';

        if ($folder) {
            $folder = rtrim(wp_normalize_path($folder), '/');
            wp_mkdir_p($folder) or $folder = '';
        }

        $this->maybeCreateHtaccess($folder);

        return $folder;
    }

    /**
     * @return array
     */
    private function handlerFileInfo(): array
    {
        /**
         * Filters the handler filename format to use.
         *
         * @param string $format
         */
        $filenameFormat = apply_filters(self::FILTER_FILENAME, '{date}.log');
        is_string($filenameFormat) and $filenameFormat = ltrim($filenameFormat, '\\/');

        /**
         * Filters the handler date format to use.
         *
         * @param string $format
         */
        $dateFormat = apply_filters(self::FILTER_DATE_FORMAT, 'Y/m/d');

        return [$filenameFormat, $dateFormat];
    }

    /**
     * When the log root folder is inside WordPress content folder,
     * the logs are going to be publicly accessible,
     * and that is in best case a privacy leakage issue,
     * in worst case a security threat.
     *
     * We try to write an .htaccess file to prevent access to them.
     * This guarantees nothing, because .htaccess can be ignored depending
     * web server in use and its configuration, but at least we tried.
     * To configure a custom log folder outside content folder is
     * also highly recommended in documentation.
     *
     * @param string $folder
     *
     * @return string
     */
    private function maybeCreateHtaccess(string $folder): string
    {
        if (
            ! $folder
            || ! is_dir($folder)
            || ! is_writable($folder)
            || file_exists("{$folder}/.htaccess")
            || ! defined('WP_CONTENT_DIR')
        ) {
            return $folder;
        }

        $targetDir = realpath($folder);
        $contentDir = realpath(WP_CONTENT_DIR);

        // Sorry, we can't allow logs to be put straight in content folder. That's too dangerous.
        if ($targetDir === $contentDir) {
            $targetDir .= DIRECTORY_SEPARATOR . 'wonolog';
        }

        // If target dir is outside content dir, its security is up to user.
        if (strpos($targetDir, $contentDir) !== 0) {
            return $targetDir;
        }

        // Let's disable error reporting: too much file operations which might fail,
        // nothing can log them, and package is fully functional even if failing happens.
        // Silence looks like best option here.
        // phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
        set_error_handler('__return_true');

        $handle = fopen("{$folder}/.htaccess", 'w');

        if ($handle && flock($handle, LOCK_EX)) {
            $htaccess = <<<'HTACCESS'
<IfModule mod_authz_core.c>
	Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
	Deny from all
</IfModule>
HTACCESS;

            if (fwrite($handle, $htaccess)) {
                flock($handle, LOCK_UN);
                chmod("{$folder}/.htaccess", 0444);
            }
        }

        fclose($handle);

        restore_error_handler();

        return $targetDir;
    }
}
