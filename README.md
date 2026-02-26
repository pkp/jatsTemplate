JATS Template Plugin
====================

This plugin permits OJS to use a basic JATS XML document generated from the OJS
metadata and full-text extraction in cases where a better JATS XML document is
not available.

It can be used in concert with the OAI JATS plugin (available at
https://github.com/pkp/oaiJats) to deliver JATS via OAI for journals that do
not have better JATS XML available.

Note that the JATS XML this plugin delivers it not intended for publication
i.e. using Lens Reader – considerable additional improvement and quality
control would be required before the document is suitable for that purpose.

## Installation

In OJS 3.5.0.x and later, this plugin is installed by default.

For earlier versions of OJS, this plugin should be available from the Plugin Gallery.

## JATS Version

As of OJS 3.6.0.x, this plugin generates valid JATS 1.2 XML.

Previous versions of the plugin generated JATS that did not pass DTD validation, but used elements from the following JATS versions:

- OJS 3.5.0.x: JATS 1.2
- OJS 3.4.0.x: JATS 1.1
- OJS 3.3.0.x: JATS 1.1

## JATS Element Coverage

| Tag                                                                                                          | Support | Unit-tests |
|--------------------------------------------------------------------------------------------------------------|---------|------------|
| [article](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/article.html)                           | :ok:    | :ok:       |
| [front](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/front.html)                               | :ok:    | :ok:       |
| [journal-meta](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/journal-meta.html)                 | :ok:    | :ok:       |
| [journal-id](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/journal-id.html)                     | :ok:    | :ok:       |
| [journal-title-group](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/journal-title-group.html)   | :ok:    | :ok:       |
| [journal-title](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/journal-title.html)               | :ok:    | :ok:       |
| [trans-title-group](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/trans-title-group.html)       | :ok:    | :ok:       |
| [trans-title](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/trans-title.html)                   | :ok:    | :ok:       |
| [abbrev-journal-title](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/abbrev-journal-title.html) | :ok:    | :ok:       |
| [contrib-group](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/contrib-group.html)               | :ok:    | :ok:       |
| [contrib](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/contrib.html)                           | :ok:    | :ok:       |
| [name](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/name.html)                                 | :ok:    | :ok:       |
| [surname](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/surname.html)                           | :ok:    | :ok:       |
| [given-names](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/given-names.html)                   | :ok:    | :ok:       |
| [issn](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/issn.html)                                 | :ok:    | :ok:       |
| [publisher](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/publisher.html)                       | :ok:    | :ok:       |
| [publisher-name](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/publisher-name.html)             | :ok:    | :ok:       |
| [publisher-loc](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/publisher-loc.html)               | :ok:    | :x:        |
| [country](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/country.html)                           | :ok:    | :x:        |
| [uri](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/uri.html)                                   | :ok:    | :ok:       |
| [self-uri](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/self-uri.html)                         | :ok:    | :ok:       |
| [article-meta](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/article-meta.html)                 | :ok:    | :ok:       |
| [article-id](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/article-id.html)                     | :ok:    | :ok:       |
| [article-categories](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/article-categories.html)     | :ok:    | :ok:       |
| [subj-group](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/subj-group.html)                     | :ok:    | :ok:       |
| [subject](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/subject.html)                           | :ok:    | :ok:       |
| [title-group](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/title-group.html)                   | :ok:    | :ok:       |
| [article-title](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/article-title.html)               | :ok:    | :ok:       |
| [subtitle](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/subtitle.html)                         | :ok:    | :ok:       |
| [trans-subtitle](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/trans-subtitle.html)             | :ok:    | :ok:       |
| [contrib-id](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/contrib-id.html)                     | :ok:    | :x:        |
| [name-alternatives](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/name-alternatives.html)       | :ok:    | :ok:       |
| [string-name](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/string-name.html)                   | :ok:    | :ok:       |
| [collab](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/collab.html)                             | :ok:    | :x:        |
| [anonymous](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/anonymous.html)                       | :ok:    | :x:        |
| [email](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/email.html)                               | :ok:    | :ok:       |
| [role](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/role.html)                                 | :ok:    | :x:        |
| [bio](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/bio.html)                                   | :ok:    | :ok:       |
| [xref](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/xref.html)                                 | :ok:    | :ok:       |
| [aff](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/aff.html)                                   | :ok:    | :ok:       |
| [institution-wrap](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/institution-wrap.html)         | :ok:    | :ok:       |
| [institution](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/institution.html)                   | :ok:    | :ok:       |
| [institution-id](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/institution-id.html)             | :ok:    | :ok:       |
| [pub-date](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/pub-date.html)                         | :ok:    | :ok:       |
| [day](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/day.html)                                   | :ok:    | :ok:       |
| [month](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/month.html)                               | :ok:    | :ok:       |
| [year](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/year.html)                                 | :ok:    | :ok:       |
| [volume](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/volume.html)                             | :ok:    | :ok:       |
| [issue](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/issue.html)                               | :ok:    | :ok:       |
| [issue-id](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/issue-id.html)                         | :ok:    | :x:        |
| [issue-title](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/issue-title.html)                   | :ok:    | :x:        |
| [fpage](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/fpage.html)                               | :ok:    | :ok:       |
| [lpage](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/lpage.html)                               | :ok:    | :ok:       |
| [pub-history](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/pub-history.html)                   | :ok:    | :x:        |
| [event](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/event.html)                               | :ok:    | :x:        |
| [event-desc](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/event-desc.html)                     | :ok:    | :x:        |
| [date](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/date.html)                                 | :ok:    | :x:        |
| [permissions](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/permissions.html)                   | :ok:    | :ok:       |
| [copyright-statement](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/copyright-statement.html)   | :ok:    | :ok:       |
| [copyright-year](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/copyright-year.html)             | :ok:    | :ok:       |
| [copyright-holder](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/copyright-holder.html)         | :ok:    | :ok:       |
| [license](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/license.html)                           | :ok:    | :x:        |
| [license-p](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/license-p.html)                       | :ok:    | :x:        |
| [abstract](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/abstract.html)                         | :ok:    | :ok:       |
| [trans-abstract](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/trans-abstract.html)             | :ok:    | :ok:       |
| [p](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/p.html)                                       | :ok:    | :ok:       |
| [bold](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/bold.html)                                 | :ok:    | :ok:       |
| [italic](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/italic.html)                             | :ok:    | :ok:       |
| [underline](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/underline.html)                       | :ok:    | :x:        |
| [sub](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/sub.html)                                   | :ok:    | :ok:       |
| [sup](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/sup.html)                                   | :ok:    | :ok:       |
| [break](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/break.html)                               | :ok:    | :x:        |
| [ext-link](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/ext-link.html)                         | :ok:    | :ok:       |
| [kwd-group](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/kwd-group.html)                       | :ok:    | :ok:       |
| [title](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/title.html)                               | :ok:    | :ok:       |
| [kwd](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/kwd.html)                                   | :ok:    | :ok:       |
| [funding-group](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/funding-group.html)               | :ok:    | :x:        |
| [award-group](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/award-group.html)                   | :ok:    | :x:        |
| [funding-source](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/funding-source.html)             | :ok:    | :x:        |
| [award-id](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/award-id.html)                         | :ok:    | :x:        |
| [funding-statement](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/funding-statement.html)       | :ok:    | :x:        |
| [counts](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/counts.html)                             | :ok:    | :ok:       |
| [page-count](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/page-count.html)                     | :ok:    | :ok:       |
| [custom-meta-group](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/custom-meta-group.html)       | :ok:    | :x:        |
| [custom-meta](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/custom-meta.html)                   | :ok:    | :x:        |
| [meta-name](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/meta-name.html)                       | :ok:    | :x:        |
| [meta-value](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/meta-value.html)                     | :ok:    | :x:        |
| [inline-graphic](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/inline-graphic.html)             | :ok:    | :x:        |
| [body](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/body.html)                                 | :ok:    | :ok:       |
| [back](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/back.html)                                 | :ok:    | :ok:       |
| [ref-list](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/ref-list.html)                         | :ok:    | :ok:       |
| [ref](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/ref.html)                                   | :ok:    | :ok:       |
| [element-citation](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/element-citation.html)         | :ok:    | :ok:       |
| [person-group](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/person-group.html)                 | :ok:    | :ok:       |
| [pub-id](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/pub-id.html)                             | :ok:    | :ok:       |
| [source](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/source.html)                             | :ok:    | :ok:       |
| [part-title](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/part-title.html)                     | :ok:    | :ok:       |
| [data-title](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/data-title.html)                     | :ok:    | :ok:       |
| [mixed-citation](https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/mixed-citation.html)             | :ok:    | :ok:       |

## Automated Tests

```
./lib/pkp/lib/vendor/bin/phpunit ./plugins/generic/jatsTemplate/tests/functional/ArticleTest.php --configuration lib/pkp/tests/phpunit.xml
./lib/pkp/lib/vendor/bin/phpunit ./plugins/generic/jatsTemplate/tests/functional/ArticleFrontTest.php --configuration lib/pkp/tests/phpunit.xml
./lib/pkp/lib/vendor/bin/phpunit ./plugins/generic/jatsTemplate/tests/functional/ArticleBackTest.php --configuration lib/pkp/tests/phpunit.xml
./lib/pkp/lib/vendor/bin/phpunit ./plugins/generic/jatsTemplate/tests/functional/ArticleBodyTest.php --configuration lib/pkp/tests/phpunit.xml
```
