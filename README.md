Plugin port helper
------------------

This script helps porting plugins from Craft 2 to Craft 3 by generating the [Install migration](https://github.com/craftcms/docs/blob/master/en/plugin-migrations.md#install-migrations).
The script will generate all the code needed to create the tables, indexes and foreign keys as well as the code needed to remove them.

## How to use this script

Run it from commandline via the php interpreter against a Craft 2 project, that has the database tables created that you want to generate the migrations for. For example
`php pluginPortHelper.php --tablePrefix=myplugin_ --namespace=craft\\myplugin --projectPath=/path/to/project > migrations.php`

### There are a few parameters you can set:
- `tablePrefix` – Prefix to use to filter tables by. If you leave out the table prefix used by Craft in the project, the script will add it automatically.
- `namespace` – Namespace that the plugin will use in Craft 3.
- `projectPath` – Valid path to the craft folder in a Craft installation. Optional, if it's the current working directory.
- `convertLocales` – If present, the helper will attempt to convert all of Craft 2 locale references in table schemas to Craft 3 Sites. This includes field names, indexes and foreign keys.