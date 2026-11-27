Asyntai AI Chatbot for WHMCS
============================

Adds an AI chat assistant to your WHMCS client area. It answers your customers
from your own content, day and night, in over 30 languages. It can also read
your WHMCS knowledge base, so the answers match what you have already written.

Requirements
------------
- WHMCS 8.0 or newer
- PHP 7.2 or newer, with cURL
- The server must be able to reach https://asyntai.com over HTTPS
- An Asyntai account. Creating one is free, and the free plan works.

Installation
------------
1. Upload the "asyntai" folder into your WHMCS installation, so that it becomes
   /modules/addons/asyntai/
2. In WHMCS, go to System Settings > Addon Modules.
3. Find "Asyntai AI Chatbot" and click Activate.
4. Click Configure, tick the administrator roles that may use it, and save.
5. Open Addons > Asyntai AI Chatbot.
6. Click "Connect Asyntai". Sign in, or create a free account, and choose which
   website the assistant should answer from.
7. Come back to WHMCS and click Refresh. The widget is now live.

Important
---------
Activate the module from System Settings > Addon Modules in the WHMCS admin
area. Activating it any other way leaves WHMCS's own hook cache empty, and the
widget then never appears on the client area even though the module looks
connected.

Settings
--------
- Show on client area: show the widget on client area, order and ticket pages.
- Show to signed out visitors: also show it before a customer signs in.
- Send knowledge base articles: send your published articles to Asyntai.
- Send announcements: also send your published announcements.

Only published items are sent. Private knowledge base articles and unpublished
announcements stay in WHMCS.

Sending your knowledge base
---------------------------
Open Addons > Asyntai AI Chatbot and click "Send articles to Asyntai now".
Run it again after you add or edit articles. Sending the same article twice
updates it; it does not create a copy.

Removing it
-----------
Deactivate the module in System Settings > Addon Modules. The widget disappears
at once. Your connection is kept, so activating it again needs no further
setup. To break the link completely, open your Asyntai dashboard and disconnect
WHMCS there.

Support
-------
Guide:  https://asyntai.com/documentation/integrations/whmcs/
Email:  hello@asyntai.com

Changelog
---------
1.0.1 - Compatibility with WHMCS 9.1 and PHP 8.4.
1.0.0 - First release.
