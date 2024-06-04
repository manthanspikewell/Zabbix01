
# Zabbix agent

### Macros used

|Name|Description|Default|
|----|-----------|-------|
|{$AGENT.TIMEOUT}|<p>Timeout after which agent is considered unavailable. Works only for agents reachable from Zabbix server/proxy (passive mode).</p>|`3m`|

### Items

|Name|Description|Type|Key and additional info|
|----|-----------|----|-----------------------|
|Version of Zabbix agent running||Zabbix agent|agent.version<p>**Preprocessing**</p><ul><li><p>Discard unchanged with heartbeat: `1d`</p></li></ul>|
|Host name of Zabbix agent running||Zabbix agent|agent.hostname<p>**Preprocessing**</p><ul><li><p>Discard unchanged with heartbeat: `1d`</p></li></ul>|
|Zabbix agent ping|<p>The agent always returns "1" for this item. May be used in combination with `nodata()` for the availability check.</p>|Zabbix agent|agent.ping|
|Zabbix agent availability|<p>Used for monitoring the availability status of the agent.</p>|Zabbix internal|zabbix[host,agent,available]|

### Triggers

|Name|Description|Expression|Severity|Dependencies and additional info|
|----|-----------|----------|--------|--------------------------------|
|Zabbix agent is not available|<p>For passive agents only, host availability is used with `{$AGENT.TIMEOUT}` as a time threshold.</p>|`max(/Zabbix agent/zabbix[host,agent,available],{$AGENT.TIMEOUT})=0`|Average|**Manual close**: Yes|

# Zabbix agent active

### Macros used

|Name|Description|Default|
|----|-----------|-------|
|{$AGENT.NODATA_TIMEOUT}|<p>No data timeout for active agents. Consider to keep it relatively high.</p>|`30m`|
|{$AGENT.TIMEOUT}|<p>Timeout after which agent is considered unavailable.</p>|`5m`|

### Items

|Name|Description|Type|Key and additional info|
|----|-----------|----|-----------------------|
|Version of Zabbix agent running||Zabbix agent (active)|agent.version<p>**Preprocessing**</p><ul><li><p>Discard unchanged with heartbeat: `1d`</p></li></ul>|
|Host name of Zabbix agent running||Zabbix agent (active)|agent.hostname<p>**Preprocessing**</p><ul><li><p>Discard unchanged with heartbeat: `1d`</p></li></ul>|
|Zabbix agent ping|<p>The agent always returns "1" for this item. May be used in combination with `nodata()` for the availability check.</p>|Zabbix agent (active)|agent.ping|
|Active agent availability|<p>Availability of active checks on the host. The value of this item corresponds to availability icons in the host list.</p><p>Possible values:</p><p>0 - unknown</p><p>1 - available</p><p>2 - not available</p>|Zabbix internal|zabbix[host,active_agent,available]|

### Triggers

|Name|Description|Expression|Severity|Dependencies and additional info|
|----|-----------|----------|--------|--------------------------------|
|Zabbix agent is not available|<p>For active agents, `nodata()` with `agent.ping` is used with `{$AGENT.NODATA_TIMEOUT}` as a time threshold.</p>|`nodata(/Zabbix agent active/agent.ping,{$AGENT.NODATA_TIMEOUT})=1`|Average|**Manual close**: Yes|
|Active checks are not available|<p>Active checks are considered unavailable. Agent has not sent a heartbeat for a prolonged time.</p>|`min(/Zabbix agent active/zabbix[host,active_agent,available],{$AGENT.TIMEOUT})=2`|High||

## Feedback

Please report any issues with the template at [`https://support.zabbix.com`](https://support.zabbix.com)

You can also provide feedback, discuss the template, or ask for help at [`ZABBIX forums`](https://www.zabbix.com/forum/zabbix-suggestions-and-feedback)

