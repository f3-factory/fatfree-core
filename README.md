# Fat-Free Framework core library

Just the raw core source code, nothing else.

![Packagist Version](https://img.shields.io/packagist/v/bcosca/fatfree-core)

More resources:

- [testing bench and unit tests](https://github.com/f3-factory/fatfree-dev)
- [documentation and user guides](https://fatfreeframework.com)
- https://github.com/bcosca/fatfree

![Matrix](https://img.shields.io/matrix/fat-free-framework%3Amatrix.org?label=chat%20on%20Matrix&link=https%3A%2F%2Fmatrix.to%2F%23%2F%23fat-free-framework%3Amatrix.org)

### Usage:

**with composer:**

```
composer require bcosca/fatfree-core
```

```php
require 'vendor/autoload.php';
$f3 = \F3\Base::instance();
```

**without composer:**

```php
$f3 = require('lib/base.php');
```
