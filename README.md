# ImportT1 module for Thelia 2 #

This module will import a Thelia 1.5.x database into the local Thelia 2 database. The following information will be imported :

- Customers.
- The complete catalog, with images and documents, features and attributes.
- Folders and contents, with images and documents.
- Orders.

**Be aware that the related content of your database will be deleted, so be sure to backup it before starting the importation process.**

The import process needs an access to your Thelia 1 database. This could be the real (live) database, but is is safer to make a copy of this database (e.g. an export / import), and start the import on this copy.

If you want to import images and documents, you'll have to provide the absolute path to the 'client' directory of your Thelia 1 installation.
As the required folders are `client/gfx`, `client/document` and `client/commande`, this is the only ones you need to copy on the local machine if your Thelia 1 installation is located 
somewhere else.

It is **recommended** to start the import process on a fresh Thelia 2 database, to prevent any inconsistencies

Once you've completed a full import, you can choose to only customers and orders. This way, you can prepare
your Thelia 2 shop while keeping the Thelia 1 shop opened, and get the last customers and orders when the
shop is switched to Thelia 2.

## How to install

This module must be into your ```modules/``` directory (thelia/local/modules/).

You can download the .zip file of this module or clone it into your project like this :

```
cd /path-to-thelia
git clone https://github.com/thelia-modules/importT1.git local/modules/ImportT1
```

Next, go to your Thelia admin panel for module activation.

## Customer passwords ##

Once the import ended, this module must remain enabled ! A temporary table (customer_temp) is created for storing password in Thelia 1 format.

## Payment and Delivery modules ##

Before starting the importation, please be sure that at least one payment module and one delivery module are installed and activated.

Unfortunately, the import process cannot find the real delivery and payment modules used by your customers during their orders on Thelia 1. Thus, the import process
will use the first payment and delivery modules found in Thelia 2, and assign them to imported orders.

## Log ##

The whole importation process is logged in the `log/import-log.txt` file, which contains all record created, and the possible errors encountered during the importation.

## Correspondance tables ##

The importation process creates several t1_t2_xxxxxx tables in your Thelia 1 database. Once the importation is finished, you can safely delete these tables.
