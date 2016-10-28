{* Confirmation of contribution update on Gift Aid status  *}

<div class="status">
  <p><span class="icon inform-icon"></span><strong>{ts}Are you sure you want to update the selected contributions? This operation cannot be undone.{/ts}</strong></p>

  <p>You should do this once you have successfully exported the contributions and prepared your Gift Aid claim.</p>
  <p>This will be claim {$nextClaimId}.</p>
  <p>{include file="CRM/Contribute/Form/Task.tpl"}</p>
</div>

<div class="crm-submit-buttons">
{include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
