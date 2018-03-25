# PhanUnusedVariable

A plugin for phan/phan that tries to detect unused variables in class methods.

## Overview
This is plugin is in a very early WIP stage.
Major todos:
 - Refactor code duplication and unnecessary recursion
 - Find cases it does not catch

## Run tests

```
$ composer install
$ cd tests && sh test.sh
```

A summary is printed at the bottom of the output comparing the actual output with the expected output.
