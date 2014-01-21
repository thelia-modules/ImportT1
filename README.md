# ImportT1 module for Thelia 2 #

This module will import a Thelia 1.5.x database into the local Thelia 2 database. The following information will be imported :

- Customers.
- The complete catalog, with images and documents, featurees and attributes.
- Folders and contents, with images and documents.
- Orders.

** Be aware that the related content of your database will be deleted, so be sure to backup it before starting the importation process.**

The import process needs an access to your Thelia 1 database. This could be the real (live) database, but is is safer to make a copy of this database (e.g. an export / import), and start the import on this copy.

If you want to import images and documents, you'll have to provide the absolute path to the 'client' directory of your Thelia 1 installation.
As the required folders are `client/gfx`, `client/document` and `client/commandes`, this is the only ones you need to copy on the local machine if your Thelia 1 installation is located 
somewhere else.

## Log ##

The whole importation process is logged in the `log/import-log.txt` file, which contains all record created, and the possible errors encountered during the importation.
