Tiny Tiny RSS on OpenShift
==========================

This git repository helps you get up and running quickly w/ a Tiny Tiny RSS
installation on OpenShift.  The backend database is Postgresql and the database
name is the same as your application name (using $_ENV['OPENSHIFT_APP_NAME']).
You can name your application whatever you want.  However, the name of the
database will always match the application.


Running on OpenShift
----------------------------

Create an account at http://openshift.redhat.com/

Create a php-5.3 application (you can call your application whatever
you want)

    rhc app create -a tinytiny -t php-5.3

Add MongoDB support to your application

    rhc cartridge add -a tinytiny -c postgresql-8.4

Add this upstream Etherpad repo

    cd etherpad
    git remote add upstream -m master git://github.com/wshearn/tinytinyrss-example.git
    git pull -s recursive -X theirs upstream master

Then push the repo upstream

    git push

That's it, you can now checkout your application at (default admin account
is admin/password):

    http://tinytiny-$yournamespace.rhcloud.com


NOTES:

GIT_ROOT/.openshift/action_hooks/post_deploy:
    This script is executed with every 'git push'.  Feel free to modify
    this script to learn how to use it to your advantage.  By default,
    this script will create the database tables that this example uses.

