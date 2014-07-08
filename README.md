GS-Nominatim
============

GNU social plugin using [Nominatim](http://wiki.openstreetmap.org/wiki/Nominatim) service to get human-readable names for locations based on user-provided lat/long pairs.

## Installation

Make sure the files are in a folder called `Nominatim`  
Put the folder in your `/plugins/` directory  
Tell `/config.php` to use it with: `addPlugin('Nominatim');`

## Configuration

All properties are optional. The values below are the default.  
The "credits" propery will be shown in the footer of your instance.

```php
$config['nominatim']['host'] = 'open.mapquestapi.com/nominatim/v1'
$config['nominatim']['credits'] = '<p>Nominatim Search Courtesy of <a href="http://www.mapquest.com/">MapQuest</a></p>'
```
