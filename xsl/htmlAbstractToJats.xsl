<?xml version="1.0" encoding="UTF-8"?>
<!--
    HTML to JATS Abstract Converter

    Based on work from the Janeway project (https://github.com/openlibhums/janeway)
    Copyright (c) Janeway Contributors
    Original work licensed under GNU Affero General Public License v3.0 (AGPL-3.0)

    Modifications for OJS jatsTemplate plugin:
    Copyright (c) 2014-2026 Simon Fraser University
    Copyright (c) 2003-2026 John Willinsky

    Changes from original:
    - Fixed tag mismatches for JATS compliance
    - Match the no-namespace elements produced by DOMDocument::loadHTML()
    - Prevented duplicate <p> nesting
    - Removed prefixes from JATS elements
    - Removed <title> elements for JATS spec compliance
    - Convert <ul>/<ol> (including nested lists) to <list>/<list-item>
    - Group inline content into paragraphs, as JATS abstracts and list items allow no bare text

    This file is part of the jatsTemplate plugin for Open Journal Systems.

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program. If not, see <https://www.gnu.org/licenses/agpl-3.0.html>.
-->
<xsl:stylesheet
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:xlink="http://www.w3.org/1999/xlink"
    version="1.0">

    <xsl:output method="xml" encoding="UTF-8" omit-xml-declaration="yes"/>

    <!-- The editor stores empty list items and blank lines as a non-breaking space, which normalize-space() keeps -->
    <xsl:variable name="nbsp" select="'&#160;'"/>

    <!--
        Runs of inline content (text and inline elements) that sit between the block elements
        (<p>, <ul>, <ol>) of the body or of a list item, keyed by their parent plus the block
        element preceding the run (empty for the leading run).
    -->
    <xsl:key name="inline-run"
        match="body/node()[not(self::p or self::ul or self::ol)] | li/node()[not(self::p or self::ul or self::ol)]"
        use="concat(generate-id(..), '|', generate-id(preceding-sibling::*[self::p or self::ul or self::ol][1]))"/>

    <!-- Match the root: the HTML parser always wraps the content in html/body -->
    <xsl:template match="/">
        <abstract>
            <xsl:for-each select="html/body">
                <xsl:call-template name="block-content"/>
            </xsl:for-each>
        </abstract>
    </xsl:template>

    <!-- Emit the block elements of the context node, wrapping each inline run in <p> -->
    <xsl:template name="block-content">
        <xsl:call-template name="inline-run">
            <xsl:with-param name="nodes" select="key('inline-run', concat(generate-id(.), '|'))"/>
        </xsl:call-template>
        <xsl:for-each select="p | ul | ol">
            <xsl:apply-templates select="."/>
            <xsl:call-template name="inline-run">
                <xsl:with-param name="nodes" select="key('inline-run', concat(generate-id(..), '|', generate-id(.)))"/>
            </xsl:call-template>
        </xsl:for-each>
    </xsl:template>

    <!-- Wrap an inline run in <p>, skipping runs without text -->
    <xsl:template name="inline-run">
        <xsl:param name="nodes"/>
        <xsl:if test="$nodes[normalize-space(translate(., $nbsp, ' '))]">
            <p><xsl:apply-templates select="$nodes"/></p>
        </xsl:if>
    </xsl:template>

    <!-- Process <p>, skipping empty paragraphs -->
    <xsl:template match="p">
        <xsl:if test="normalize-space(translate(., $nbsp, ' '))">
            <p><xsl:apply-templates/></p>
        </xsl:if>
    </xsl:template>

    <!-- Top level list: <abstract> only allows <p> and <sec>, so the list goes in a <p> -->
    <xsl:template match="body/ul | body/ol">
        <xsl:if test="normalize-space(translate(., $nbsp, ' '))">
            <p><xsl:call-template name="list"/></p>
        </xsl:if>
    </xsl:template>

    <!-- Nested list: <list-item> allows <list> next to its <p> -->
    <xsl:template match="li/ul | li/ol">
        <xsl:if test="normalize-space(translate(., $nbsp, ' '))">
            <xsl:call-template name="list"/>
        </xsl:if>
    </xsl:template>

    <!-- List placed directly inside a list (invalid HTML, e.g. pasted content) gets its own <list-item> -->
    <xsl:template match="ul/ul | ul/ol | ol/ul | ol/ol">
        <xsl:if test="normalize-space(translate(., $nbsp, ' '))">
            <list-item><xsl:call-template name="list"/></list-item>
        </xsl:if>
    </xsl:template>

    <!-- <ul> becomes a bullet list, <ol> an order list; items without text are dropped -->
    <xsl:template name="list">
        <list>
            <xsl:attribute name="list-type">
                <xsl:choose>
                    <xsl:when test="self::ol">order</xsl:when>
                    <xsl:otherwise>bullet</xsl:otherwise>
                </xsl:choose>
            </xsl:attribute>
            <xsl:apply-templates select="(li | ul | ol)[normalize-space(translate(., $nbsp, ' '))]"/>
        </list>
    </xsl:template>

    <!-- <li> becomes <list-item> -->
    <xsl:template match="li">
        <list-item><xsl:call-template name="block-content"/></list-item>
    </xsl:template>

    <!-- <br> becomes a space, as <break> is not allowed in <p> -->
    <xsl:template match="br">
        <xsl:text> </xsl:text>
    </xsl:template>

    <!-- <strong> / <b> -->
    <xsl:template match="strong | b">
        <bold><xsl:apply-templates/></bold>
    </xsl:template>

    <!-- <em> / <i> -->
    <xsl:template match="em | i">
        <italic><xsl:apply-templates/></italic>
    </xsl:template>

    <!-- <u> / <span style="text-decoration: underline;"> -->
    <xsl:template match="u | span[contains(@style, 'underline')]">
        <underline><xsl:apply-templates/></underline>
    </xsl:template>

    <!-- <sub> -->
    <xsl:template match="sub">
        <sub><xsl:apply-templates/></sub>
    </xsl:template>

    <!-- <sup> -->
    <xsl:template match="sup">
        <sup><xsl:apply-templates/></sup>
    </xsl:template>

    <!-- mailto -->
    <xsl:template match="a[starts-with(@href, 'mailto:')]" priority="1">
        <email>
            <xsl:value-of select="substring-after(@href, 'mailto:')"/>
        </email>
    </xsl:template>

    <!-- external link -->
    <xsl:template match="a[@href]">
        <ext-link ext-link-type="uri">
            <xsl:attribute name="xlink:href">
                <xsl:value-of select="@href"/>
            </xsl:attribute>
            <xsl:apply-templates/>
        </ext-link>
    </xsl:template>

    <!-- Fallback for unhandled tags (span, s, a without href, ...): drop the tag, keep content -->
    <xsl:template match="*">
        <xsl:apply-templates/>
    </xsl:template>

</xsl:stylesheet>
