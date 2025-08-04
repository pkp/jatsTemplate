<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet 
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:xlink="http://www.w3.org/1999/xlink"
    xmlns:html="http://www.w3.org/1999/xhtml"
    version="1.0">

    <xsl:output method="xml" indent="yes" encoding="UTF-8" omit-xml-declaration="yes"/>

    <!-- Match the root (html, body, or direct content) -->
    <xsl:template match="/ | html:html | html:body">
        <abstract>
            <xsl:apply-templates select="html:p | .//text()[normalize-space() and not(ancestor::html:p)]"/>
        </abstract>
    </xsl:template>

    <!-- Process <p> -->
    <xsl:template match="html:p">
        <p><xsl:apply-templates/></p>
    </xsl:template>

    <!-- Wrap stray text in <p> -->
    <xsl:template match="text()[normalize-space()]">
        <p><xsl:value-of select="normalize-space(.)"/></p>
    </xsl:template>

    <!-- <li> becomes <p> -->
    <xsl:template match="html:li">
        <p><xsl:apply-templates/></p>
    </xsl:template>

    <!-- <ul> just applies templates -->
    <xsl:template match="html:ul">
        <xsl:apply-templates/>
    </xsl:template>

    <!-- <br> -->
    <xsl:template match="html:br">
        <break/>
    </xsl:template>

    <!-- <strong> / <b> -->
    <xsl:template match="html:strong | html:b">
        <bold><xsl:apply-templates/></bold>
    </xsl:template>

    <!-- <em> / <i> -->
    <xsl:template match="html:em | html:i">
        <italic><xsl:apply-templates/></italic>
    </xsl:template>

    <!-- <sub> -->
    <xsl:template match="html:sub">
        <sub><xsl:apply-templates/></sub>
    </xsl:template>

    <!-- <sup> -->
    <xsl:template match="html:sup">
        <sup><xsl:apply-templates/></sup>
    </xsl:template>

    <!-- <span style="text-decoration: underline;"> -->
    <xsl:template match="html:span[contains(@style, 'underline')]">
        <underline><xsl:apply-templates/></underline>
    </xsl:template>

    <!-- generic span — just keep content -->
    <xsl:template match="html:span">
        <xsl:apply-templates/>
    </xsl:template>

    <!-- <s> — drop tag, keep content -->
    <xsl:template match="html:s">
        <xsl:apply-templates/>
    </xsl:template>

    <!-- mailto -->
    <xsl:template match="html:a[starts-with(@href, 'mailto:')]">
        <email>
            <xsl:value-of select="substring-after(@href, 'mailto:')"/>
        </email>
    </xsl:template>

    <!-- external link -->
    <xsl:template match="html:a">
        <ext-link>
            <xsl:attribute name="xlink:href">
                <xsl:value-of select="@href"/>
            </xsl:attribute>
            <xsl:apply-templates/>
        </ext-link>
    </xsl:template>

    <!-- Handle text nodes -->
    <xsl:template match="text()">
        <xsl:value-of select="normalize-space(.)"/>
    </xsl:template>

    <!-- Fallback for unhandled tags -->
    <xsl:template match="*">
        <xsl:apply-templates/>
    </xsl:template>

</xsl:stylesheet>
