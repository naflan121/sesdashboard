.. index:: Commands

Commands
========

Clear old emails data
---------------------

::

$ ./bin/console app:emails:cleanup --days=7

Create admin user
-----------------

::

$ ./bin/console app:create-user --admin
OR
$ make create-admin

Create a project
----------------

Creates a project for an existing user and prints its webhook path, so an install can be
provisioned without going through the UI.

::

$ ./bin/console app:create-project admin@example.com "My Project"

Pass ``--token`` to pin a known webhook token instead of generating a random one, which is
useful when the SNS subscription is created by the same script:

::

$ ./bin/console app:create-project admin@example.com "My Project" --token=my-fixed-token
