<?php
# Copyright (c) 2017, CESNET. All rights reserved.
#
# Redistribution and use in source and binary forms, with or
# without modification, are permitted provided that the following
# conditions are met:
#
#   o Redistributions of source code must retain the above
#     copyright notice, this list of conditions and the following
#     disclaimer.
#   o Redistributions in binary form must reproduce the above
#     copyright notice, this list of conditions and the following
#     disclaimer in the documentation and/or other materials
#     provided with the distribution.
#
# THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND
# CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES,
# INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
# MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
# DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS
# BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,
# EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED
# TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
# DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
# ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
# OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY
# OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
# POSSIBILITY OF SUCH DAMAGE.

require(realpath(dirname(__FILE__)) . '/../../common/Loader.php');

header("Content-Type: text/xml; charset=utf-8");

$pakiti = new Pakiti();

$cveExceptions = $pakiti->getDao("CveException")->getCvesExceptions();

$pkgDao = $pakiti->getDao("Pkg");
$osGroupsManager = $pakiti->getManager("OsGroupsManager");

$xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?> <cveExceptions></cveExceptions>');

foreach ($cveExceptions as $cveException) {
    $cveExceptionNode = $xml->addChild("cveException");
    $cveExceptionNode->addChild("cveName", $cveException->getCveName());
    $cveExceptionNode->addChild("reason", $cveException->getReason());
    $cveExceptionNode->addChild("modifier", Config::$PAKITI_NAME);
    $pkg = $pkgDao->getById($cveException->getPkgId());
    $pkgNode = $cveExceptionNode->addChild("pkg");
    $pkgNode->addChild("name", $pkg->getName());
    $pkgNode->addChild("version", $pkg->getVersion());
    $pkgNode->addChild("release", $pkg->getRelease());
    $pkgNode->addChild("arch", $pkg->getArchName());
    $pkgNode->addChild("type", $pkg->getPkgTypeName());
    $osGroup = $osGroupsManager->getOsGroupById($cveException->getOsGroupId());
    $osGroupNode = $cveExceptionNode->addChild("osGroup");
    $osGroupNode->addChild("name", $osGroup->getName());
}

print($xml->asXML());