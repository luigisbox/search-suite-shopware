# Luigi's Box Search Suite plugin for Shopware

Luigi's Box is an Award Winning Search Solution for eCommerce, providing Search Analytics and Search as a Service.

This repository holds composer package of a Shopware 5 plugin, providing integration between Shopware store & Luigi's Box services. To use it, you need to have an account on Luigi's Box platform. Go and create one if you do not have it already.

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

- Be sure that `Cron` plugin is active in ShopWare. Open Plugin Manager (Ctrl+Alt+P) and then check, that "Cron" by "Shopware AG" is among installed & active plugins.
- Require plugin from composer `composer require luigisbox/search-suite-shopware`
- Refresh plugin list and activate via command line or from backend.

```
$ php ./bin/console sw:plugin:refresh
Successfully refreshed

$ php ./bin/console sw:plugin:install --activate luigisbox/search-suite-shopware
```

Once you completed these steps, you are done with the installation. Now please go to configuration of the plugin via Plugin Manager and configure the plugin there.

Once configured, you can proceed with:
- Clearing the cache `Configuration > Cache / Performance> Clear shop cache` or from commadline `php bin/console sw:cache:clear`
- Run the initial synchronization of catalog manually via `php bin/console sw:cron:run Shopware_CronJob_SendToLuigisBoxApi`

## Run indexing manually

If plugin settings are configured the user can run manually the indexing from the menu: `Marketing > Luigi's Box Search Suite Indexing`
