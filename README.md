# PhanUnusedVariable
A plugin for etsy/phan that tries to detect unused variables in class methods.

## Overview
This is plugin is in a very early WIP stage.
Major todos
 - Add a "real" testsuite
 - Refactor code duplication and unnecessary recursion
 - Find cases it does not catch

## Run tests

```
$ composer install
$ cd test && ./../vendor/bin/phan -p
```

Expected output is issues on variables $one to $nineteen and nothing else.
