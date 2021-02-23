#!/bin/bash

# fly --target example login --team-name main --concourse-url http://localhost:8080

fly -t example set-pipeline -p zabbix-test2 -c zabbix-test2-pipeline.yml && \
fly -t example unpause-pipeline -p zabbix-test2 && \
fly -t example trigger-job -j zabbix-test2/job-test-zabbix && \
fly -t example watch -j zabbix-test2/job-test-zabbix

# fly -t example hijack -j zabbix-test2/job-test-zabbix

# fly -t example destroy-pipeline -p zabbix-test
