KLIKANDPAY PAYMENT FOR THELIA V2
--------------------------------------

This module add the secure payment method [Klik & Pay][1] on your site.

> **Author**
>
> Christophe Laffont - OpenStudio / [www.openstudio.fr][2]


INSTALLATION
----------


To install this module, you will need:

 1. Download the archive uncompress in the folder `/local/modules/` on your site.
 2. In your backOffice, click on the menu ***Modules*** and enable Klik & Pay under the ***PAYMENT MODULES*** section.
 3. You need to configure the module with your account id:
    (The **account identifier** is available in your Klik & Pay backoffice, menu `Account Administration > Information on the account`. )
 4. You must also set your returns URL in your Klik & Pay backoffice, menu `Account Set-up > Set-up`,
 
    > **URL transaction accepted**: http://www.yourdomain.com/klikandpay/order/placed
    > **URL refused/cancelled transaction**: http://www.yourdomain.com/klikandpay/order/failed
 5. Finally, to automatically validate a transaction, you have to set the Dynamic Return URL in your klik & pay backoffice, menu `Account Set-up > Dynamic Return`.
    > **Dynamic Return URL**: http://www.yourdomain.com/klikandpay/confirmation?commande=
    
    **Important:** If you use the dynamic return URL, it's also very important to checked the value **MONTANTXKP** (Transaction amount).

----------

FREQUENTLY ASKED QUESTIONS
---------

**I added a product in my cart , but I don't see the secure payment Klik & Pay?**
To use the secure payment Klik & Pay , you must make sure you have fill in the backoffice of your shop,
`Modules > Klik&Play > Configure`, all the mandatory information.

**I configured the module parameters, but I still don't see the secure payment Klik & Pay?**
You must ensure that the amount of your cart is not less than the **Minimum Amount** or above the **Maximum amount**.

**Why my website is not authorized to access the secure payment Klik & Pay?**
Klik & Pay verifies the origin of transactions. Only sites declared under `Account Set-Up > Set-up` are allowed to send transactions.

**Will my card be charged if I try to order a product in TEST mode**
No, the **TEST** mode works exactly like **PRODUCTION** mode but does not allow the sending bank transactions.

**My order is not validated while the secure payment Klik & Pay worked well?**
- You must ensure that the **Dynamic URL** in the backoffice of Klik & Pay `Account Set-Up > Dynamic Return`
has been set and that the file exists on your site. Make sure you have checked the value `MONTANTXKP` to returned.


----------

CHANGELOG
---------

- **1.0.0** ( 19/03/2014 ) - First version of the module


@TODO
---------

* Add other payment methods Klik & Pay (Deferred Payment / Payment by Subscription / Payment in X instalments / E-mail Payment).
* Add a page to view order history Klik & Pay


  [1]: http://www.klikandpay.com
  [2]: http://www.openstudio.fr