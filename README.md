# Luigi's Box Search Suite plugin for Shopware5 - development preview

Luigi's Box is an Award Winning Search Solution for eCommerce, providing Search Analytics and Search as a Service.

This repository holds composer package of a Shopware 5 plugin, providing integration between Shopware store & Luigi's Box services. To use it, you need to have an account on Luigi's Box platform. Go and create one if you do not have it already.


!!! This solution is not meant to be production-ready. Use it as an inspiration how synchronization between Luigi's Box and Shopware5 can be achieved.


## Basic info

The plugin uses Guzzle Http 5.

- `plugin.xml` - describes the plugin metadata and dependencies to other plugins. Currently, only the `Cron` plugin is needed to run the cron jobs.
- `LuigisBoxSearchSuite.php` - holds the main event handlers, which ensure that any change in products catalog is propagated to Luigi's Box backends. See the implementation for more info.


### Helpers

- `Models\Helper.php` - contains helper functions to check if cron job has to be executed.

## Resources

- `config.xml` - holds description of user interface and is picked by ShopWare to build the settings form of the plugin.
- `cronjob.xml` - schedule a nightly cron job event syncing all the products to Luigi's Box backends. The plugin is subscribed to it.
- `menu.xml` - Menu item for run indexing manually
- `views` - contains the overriden template files.

## Installation

- Be sure that `Cron` plugin is active in Shopware. Open Plugin Manager (Ctrl+Alt+P) and then check, that "Cron" by "Shopware AG" is among installed & active plugins.
- Require plugin from composer `composer require luigisbox/search-suite-shopware5`
- Refresh plugin list and activate via command line or from backend.

```
$ php ./bin/console sw:plugin:refresh
Successfully refreshed

$ php ./bin/console sw:plugin:install --activate luigisbox/search-suite-shopware5
```

Once you completed these steps, you are done with the installation. Now please go to configuration of the plugin via Plugin Manager and configure the plugin there.

Once configured, you can proceed with:
- Clearing the cache `Configuration > Cache / Performance> Clear shop cache` or from commadline `php bin/console sw:cache:clear`
- Run the initial synchronization of catalog manually via `php bin/console sw:cron:run Shopware_CronJob_SendToLuigisBoxApi` or by invoking the action through Marketing > Luigi's Box Search Suite Indexing menu.

!!! The cache clearing step is very important. Make sure to clear the cache after any change in plugin configuration. Otherwise the configuration changes will not be picked up.

Finally, you must ensure that cron jobs are executed periodically at your Shopware instance. It is possible that you might already have this setup at your Shopware instance if you are using cron for other tasks. Please, refer to [Shopware online documentation](https://docs.shopware.com/en/shopware-5-en/settings/system-cronjobs#setting-up-a-cronjob) to find out more details. 

As a quick reference, we recommend to setup cron jobs so that they run every 5 minutes by adjusting crontab as follows:

```
*/5 * * * * cd /path/to/your/shopware-installation && php bin/console sw:cron:run
```

Make sure that this is set for the same user as the one who is running the Shopware (e.g., www-data).


## Manual indexing and cronjobs adjustments

You can always trigger a manual reindex by pressing the "Reindex now" button after clicking at Marketing > Luigi's Box Search Suite Indexing menu item.

If you wish to adjust the time when the daily full reindex takes place, you can do this under Configuration > Basic Settings > System > Cronjobs. The name of the job you are looking for is `Shopware_CronJob_SendToLuigisBoxApi`.

If you wish to change the default 5 minutes window, you can do so by editing properties of the second cron job, `Shopware_CronJob_UpdateLuigisBoxApi`.
