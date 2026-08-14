<div class="crm-block">
  <h3>QuickBooks Connection</h3>
  <p>Environment: <strong>{$env}</strong></p>
  {if $connected}
    <p>Connected to company {$realmId}.</p>
    <a class="button" href="{crmURL p='civicrm/admin/boosterportal/qbo' q='connect=1'}">Reconnect</a>
  {else}
    <p>Not connected.</p>
    <a class="button" href="{crmURL p='civicrm/admin/boosterportal/qbo' q='connect=1'}">Connect to QuickBooks</a>
  {/if}
</div>
