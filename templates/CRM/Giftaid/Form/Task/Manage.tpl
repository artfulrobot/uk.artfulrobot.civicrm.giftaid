{* Manage Gift Aid contributions Task. *}
<table>
  <thead>
    <tr><th>Status</th><th>&pound;</th><th>Contributions</th><th>Contacts</th><th>Actions</th></tr>
  </thead>
  <tbody>
    {if $gaSummary.unclaimed_ok.total>0}
    <tr>
      <td>Could claim</td>
      <td>&pound;{$gaSummary.unclaimed_ok.total}</td>
      <td>{$gaSummary.unclaimed_ok.count}</td>
      <td>{$gaSummary.unclaimed_ok.contacts|@count}</td>
      <td>{$form.unclaimed_ok_include.html}
      {$form.unclaimed_ok_include.label}
        <p>These contributions are marked eligible and as-yet unclaimed and all belong to contacts with name and address information.</p>
      </td>
    </tr>
    {/if}
    {if $gaSummary.unclaimed_aggregate.total>0}
    <tr>
      <td>Could claim under aggregate</td>
      <td>&pound;{$gaSummary.unclaimed_aggregate.total}</td>
      <td>{$gaSummary.unclaimed_aggregate.count}</td>
      <td>{$gaSummary.unclaimed_aggregate.contacts|@count}</td>
      <td>
        <p>These contributions belong to contacts with missing name and/or
        address data. However it is possible to claim gift aid on these by
        aggregating them together since every contribution is less than
        &pound;20.</p>
        {$form.unclaimed_aggregate_include.html}
        {$form.unclaimed_aggregate_include.label}
      </td>
    </tr>
    {/if}
    {if $gaSummary.unclaimed_missing_data.total>0}
    <tr>
      <td>Unclaimable</td>
      <td>&pound;{$gaSummary.unclaimed_missing_data.total}</td>
      <td>{$gaSummary.unclaimed_missing_data.count}</td>
      <td>{$gaSummary.unclaimed_missing_data.contacts|@count}</td>
      <td><p>These contributions belong to contacts with missing name and/or
      address data and because they are greater than &pound;20, they cannot be
      claimed as part of an aggregate line. So these will remain unclaimable
      until you find the person's details.</p>
      </td>
    </tr>
    {/if}
    {if $gaSummary.claimed.total>0}
    <tr>
      <td>Claimed</td>
      <td>&pound;{$gaSummary.claimed.total}</td>
      <td>{$gaSummary.claimed.count}</td>
      <td>{$gaSummary.claimed.contacts|@count}</td>
      <td><p>These contributions have already had Gift Aid claimed</p>
        <p>Note that regenerating the claim spreadsheet will not change any
        data, however, data such as name and address may have changed in the
        database since the original claim was made. This is a convenience
        function; you should keep records of all files you submit to HMRC.</p>
        <button name="_qf_Manage_submit" class="crm-form-submit" value="regenerate_claimed" >Regenerate Claim</button>
      </td>
    </tr>
    {/if}
    {if $gaSummary.ineligible.total>0}
    <tr>
      <td>Ineligible</td>
      <td>&pound;{$gaSummary.ineligible.total}</td>
      <td>{$gaSummary.ineligible.count}</td>
      <td>{$gaSummary.ineligible.contacts|@count}</td>
      <td><p>These contributions are ineligible for Gift Aid (e.g. they were not received during a period of eligibility).</p>
      </td>
    </tr>
    {/if}
    {if $gaSummary.unknown.total>0}
    <tr>
      <td>Unknown Eligibility</td>
      <td>&pound;{$gaSummary.unknown.total}</td>
      <td>{$gaSummary.unknown.count}</td>
      <td>{$gaSummary.unknown.contacts|@count}</td>
      <td><p>These contributions have not been compared against the respective contacts' declarations of eligibility yet.</p>
      <button name="_qf_Manage_submit" class="crm-form-submit" value="determine_eligibility_unknown" >Determine Eligibility</button>
      </td>
    </tr>
    {/if}
    {if $gaSummary.no_data.total>0}
    <tr>
      <td>Old Contributions</td>
      <td>&pound;{$gaSummary.no_data.total}</td>
      <td>{$gaSummary.no_data.count}</td>
      <td>{$gaSummary.no_data.contacts|@count}</td>
      <td><p>These do not even have 'unknown' status. They may have been put in before the Gift Aid extension was installed, or they may have been created by some other system.</p>
      {$form.no_data.html}
      </td>
    </tr>
    {/if}
  </tbody>
</table>

{if $gaSummary.unclaimed_ok.total>0 or $gaSummary.unclaimed_aggregate.total>0}
  <h2>New Claim</h2>
  <p>Pressing the button below will create a spreadsheet suitable for
  copy-and-pasting into HMRC's template to make a claim from the contributions
  you've selected to include above.<strong>It will also
  update the claim status of all the as-yet unclaimed contributions to
  'claimed'. </strong></p>

  {if $gaSummary.earliest}
  <p>Contribution dates range from <strong>{$gaSummary.earliest}</strong> to <strong>{$gaSummary.latest}</strong>. HMRC requires claims to fall within your charity's financial year, so check that these dates do.</p>
  {/if}

  <button name="_qf_Manage_submit" class="crm-form-submit" value="create_claim" >Create Claim</button>
{/if}

{*include file="CRM/Contribute/Form/Task.tpl"*}

<div class="crm-submit-buttons">
{*include file="CRM/common/formButtons.tpl" location="bottom"*}
</div>
