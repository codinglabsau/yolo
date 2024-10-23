# YOLO

YOLO is a deployment tool inspired by Laravel Vapor CLI. 

YOLO sits approximately in between Laravel Forge and Laravel Vapor. It creates highly available resources on AWS, but uses EC2 instead of Lambda.

The goal is to heavily leverage AWS, and support moderate to very heavy traffic web apps. 

___
## Installation

### Install With Composer
```bash
composer require codinglabsau/yolo
```

Optionally, install globall with:
```bash
composer global require codinglabsau/yolo
```

## Usage
See `vendor/bin/yolo` or `yolo` if you have installed globally.

### `yolo init`
Initalises the yolo.yml file in the app, and ensures the low AWS resources exist. 

## Credits
- [Steve Thomas](https://github.com/stevethomas)
- [All Contributors](https://github.com/codinglabsau/yolo/contributors)

## License
Proprietary.
