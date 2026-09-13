I want to create a set of web sites to help my kids master concepts that they want to master.  I want one system - "mastery.brianrosenthal.org" where I can set up accounts and one database.  Users should be able to log into this and publish content about topics they want to master.

The idea is that once my kids learn a concept, they need to "teach" it, and I want the web site to enable them to create videos, along with documents they can link to the videos and a description.

I also want the same database to be able to power a site for each of them.
- "mastery.charlierosenthal.org".
- "mastery.lillyrosenthal.org"

These should be sites that should show the content for the user.  It should be easy to configure this site.  And Charlie should be able to log into this site and just add stuff for him.  So, I want to be able to manage the content on mastery.brianrosenthal.org (main site with admin for the whole thing) and also Charlie should be able to manage the content on mastery.charlierosenthal.org.

This is a high-level folder for the project "mastery.brianrosenthal.org".

The overall folder layout should be:
./docs (design docs)
./www (mastery.brianrosenthal.org)

The actual functionality:

Data Model:

There should be users (let's use the same infrastructure as vocab.lillyrosenthal.org).  This is specified in ../vocab.lillyrosenthal.org.  Please go ahead and build all the user infrastructure, email log, activity log, also db_migration application, settings, etc.

There should be "categories".  Like "Algebra II".  Per user.
There should be subcategories.  Like "Sequences and Series".  Per category
There should be concepts.  Like "Derivation of e^x".  Per subcategory.  Published or not.

Each concept may have a video, a description, and supporting resources (links).

Each user should be able to create categories for that user.  And subscategories for the category.  And concepts for each subcategory.

So each user should build up a set of concepts that they have published a video about and supporting resources.

There should be a web site that should show a view of the user's published concepts.  I want the user to be proud of this web site, so I want you to think a bit about how this should work.  The user should be able to cusotmize the site - its homepage content, which should have markdown.  It should be easy to navigate to the different categories and concepts.  Categories should have a homepage with links to subcategories and direct links to concepts.  Cubcategories should have a page with a list of concepts.  Each should have markdown that the user can specify to appear on the page.

If the user is logged in, they should be able to navigate from the puslihed site to upload new content (ie, create a new category, subcategory, concept).  Or edit existing ones.  Or delete the page (in context).  If the category or subcategory is empty, the user should be able to delete it.

Also, I want to make a separate domain like https://mastery.charlierosenthal.org work for viewing the user's site (public view).  I want to host on dreamhost and I'm not sure on dreamhost how to route multiple domains to the same directory, so let's explore whether that is possible.  If it's possible, let's make an .htaccess file to make this work.

Same with https://mastery.lillyrosenthal.org
