#!/bin/sh

build_root=$PWD
echo "Build Root: ${build_root}"

cd workspace-repo

apt update
apt install -y libssl-dev
apt install -y libpcre3-dev
apt install -y automake 
apt install -y checkinstall

./bootstrap.sh
builddate=$(date --utc +%Y-%m-%d)

./configure \
        --bindir=/usr/bin \
        --sbindir=/usr/sbin \
        --datadir=/usr/lib \
        --libdir=/usr/lib/zabbix \
        --sysconfdir=/etc/zabbix \
        --program-prefix=cld- \
        --program-suffix=-$builddate \
        --with-openssl \
        --enable-agent 

make
VERSION=$(date -u +"%Y%m%dT%H%M%SZ")
echo $VERSION
checkinstall \
        --install=no \
        --fstrans=no \
        --pkgname=zabbix-agent \
        --pkgversion="$VERSION" \
        --default
cp ./zabbix-agent_$VERSION-1_amd64.deb ../workspace-repo-deb/
ls -al ../workspace-repo-deb
