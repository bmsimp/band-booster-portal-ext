<div class="crm-block booster-portal-login">
{if $sent}
  <p>{ts}If we have that address on file, a sign-in link is on its way. It works once and expires in 20 minutes.{/ts}</p>
{else}
  <h3>{ts}Sign in{/ts}</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="{$csrf}">
    <label for="email">{ts}Your email address{/ts}</label>
    <input type="email" id="email" name="email" required>
    <button type="submit">{ts}Email me a sign-in link{/ts}</button>
  </form>
{/if}
</div>
