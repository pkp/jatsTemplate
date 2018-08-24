JATS Template Plugin
====================

This plugin permits OJS to use a basic JATS XML document generated from the OJS
metadata and full-text extraction in cases where a better JATS XML document is
not available.

It is intended to be used in concert with the OAI JATS plugin (available at
https://github.com/pkp/oaiJats) to deliver JATS via OAI for journals that do
not have better JATS XML available.

Note that the JATS XML this plugin delivers it not intended for publication
i.e. using Lens Reader -- considerable additional improvement and quality
control would be required before the document is suitable for that purpose.
