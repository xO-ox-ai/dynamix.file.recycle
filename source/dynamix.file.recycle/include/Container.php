<?php
/**
 * Container.php — tiny DI container holding the plugin's singletons.
 */

declare(strict_types=1);

namespace DynamixFileRecycle;

final class Container
{
    private ?Config $config = null;
    private ?Logger $logger = null;
    private ?I18n $i18n = null;
    private ?FsInspector $fs = null;
    private ?History $history = null;
    private ?Security $security = null;

    public function config(): Config
    {
        return $this->config ??= new Config(CFG_FILE, CFG_DEFAULT);
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger(
            LOG_FILE,
            $this->config()->getLogLevel(),
            $this->config()->getLogRetentionDays(),
            $this->config()->getLogMaxMib()
        );
    }

    public function i18n(): I18n
    {
        return $this->i18n ??= new I18n(
            ROOT . '/languages',
            $this->config()->getLanguage()
        );
    }

    public function fs(): FsInspector
    {
        return $this->fs ??= new FsInspector();
    }

    public function history(): History
    {
        return $this->history ??= new History(DB_FILE, $this->logger());
    }

    public function security(): Security
    {
        return $this->security ??= new Security($this->fs(), $this->history());
    }

    public function recycler(): Recycler
    {
        return new Recycler(
            $this->fs(),
            $this->history(),
            $this->logger(),
            $this->config()
        );
    }

    public function restorer(): Restorer
    {
        return new Restorer(
            $this->fs(),
            $this->history(),
            $this->logger(),
            $this->config()
        );
    }

    public function purger(): Purger
    {
        return new Purger(
            $this->fs(),
            $this->history(),
            $this->logger()
        );
    }

    public function maintenance(): Maintenance
    {
        return new Maintenance(
            $this->config(),
            $this->fs(),
            $this->history(),
            $this->purger(),
            $this->logger()
        );
    }
}
