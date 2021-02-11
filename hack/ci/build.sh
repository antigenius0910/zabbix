#!/bin/sh

    build_root="${PWD}"

    cd resource-zabbix
    ls -al
    echo $PATH

    apt update
    apt install -y libssl-dev
    apt install -y libpcre3-dev
    apt install -y automake 

    ./bootstrap.sh
    builddate=$(date --utc +%Y-%m-%d)
    ./configure --enable-agent --with-openssl --program-prefix=cld- --program-suffix=-$builddate --bindir=/usr/bin --sbindir=/usr/sbin --sysconfdir=/etc

    make
    make install 
