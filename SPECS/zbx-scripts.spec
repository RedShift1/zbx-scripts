Name:		zbx-scripts
Version:	0.9
Release:	1%{?dist}
Summary:	Collection of Zabbix monitoring scripts

Group:		Applications/System
License:	BSD
URL:		https://github.com/RedShift1/zbx-scripts
Source0:	https://github.com/RedShift1/zbx-scripts/archive/%{version}.tar.gz

Requires:	php zabbix-sender
BuildArch:	noarch

%description


%prep
%setup -q


%install
 mkdir -p ${RPM_BUILD_ROOT}/usr/lib/zabbix/externalscripts

 cp zbx_shared.php                       ${RPM_BUILD_ROOT}/usr/lib/zabbix/externalscripts/

 install -m 0755 zbx_p2000.php           ${RPM_BUILD_ROOT}/usr/lib/zabbix/externalscripts/zbx_p2000.php
 install -m 0755 zbx_esxi_cim2.php       ${RPM_BUILD_ROOT}/usr/lib/zabbix/externalscripts/zbx_esxi_cim2.php
 install -m 0755 zbx_get_nameservers.php ${RPM_BUILD_ROOT}/usr/lib/zabbix/externalscripts/zbx_get_nameservers.php


%files
/usr/lib/zabbix/externalscripts/*
