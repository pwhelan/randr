RandR
======

[![Build Status](https://travis-ci.org/pwhelan/randr.svg?branch=logging)](https://travis-ci.org/pwhelan/randr)

RandR is a PHP Resque compatible server using React PHP. Currently it supports
the logging extensions PHP Resque Ex does but in the future these should be
abstracted away by using Evenement.

Prerequisites
-------------

RandR pretty much requires the same things as PHP Resque or PHP Resque-Ex.

  * Redis - key/value store database, used as a task queue.

Installation
------------

Use composer or github to install.

To start up a test run of RandR is easy enough:

    user@host:~Code$ git clone https://github.com/pwhelan/randr.git
    user@host:~Code$ cd randr
    user@host:~Code/amused$ APP_INCLUDE=tests/TestWorker.php QUEUES="test[4]" ./bin/randr
