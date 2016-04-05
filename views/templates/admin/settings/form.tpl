<form id="{$mod}_settings_form" class="defaultForm form-horizontal" method="post" action="{$action}">
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-cogs"></i>
            {l s="NetLicensing Connect settings" mod={$mod}}
        </div>
        <div class="alert alert-info">
            <div>
                <a target="_blank" href="https://go.netlicensing.io/app/v2/content/register.xhtml">{l s="Sign up" mod={$mod}}</a>
                {l s="for your free NetLicensing vendor account, then fill in the login information in the fields below" mod={$mod}}
            </div>
           <div>
               {l s="Using NetLicensing" mod={$mod}}
               <a target="_blank" href="https://go.netlicensing.io/app/v2/?lc=4b566c7e20&source=lmbox001">{l s="demo account" mod={$mod}}</a>
               {l s=", you can try out plugin functionality right away (username: demo / password: demo)" mod={$mod}}
           </div>
        </div>
        <div class="form-wrapper">
            <div class="form-group">
                <label class="control-label col-lg-3 required">
                    <span class="label-tooltip" title="" data-html="true" data-toggle="tooltip" data-original-title="{l s="Enter your NetLicensing username." mod={$mod}}">
                        {l s="Username" mod={$mod}}
                    </span>
                </label>
                <div class="col-lg-9">
                    <input id="{$mod}_username" class="fixed-width-lg" type="text" required="required" size="30"  value="{$username}" name="{$mod}_username">
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3 required">
                    <span class="label-tooltip" title="" data-html="true" data-toggle="tooltip" data-original-title="{l s="Enter your NetLicensing password." mod={$mod}}">
                     {l s="Password" mod={$mod}}
                    </span>
                </label>
                <div class="col-lg-9">
                    <div class="input-group fixed-width-lg">
                        <span class="input-group-addon">
                            <i class="icon-key"></i>
                        </span>
                        <input id="{$mod}_password" class="" type="password" required="required" value="{$password}" name="{$mod}_password">
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">
                    <span class="label-tooltip" title="" data-html="true" data-toggle="tooltip" data-original-title="{l s="Send email with licenses to the client automatically?" mod={$mod}}">
                        {l s="Send email automatically" mod={$mod}}
                    </span>
                </label>
                <div class="col-lg-9">
                    <div class="input-group fixed-width-lg">
                        <input id="{$mod}_send_email" name="{$mod}_send_email" type="checkbox" value="1" {if $send_email}checked{/if}>
                    </div>
                </div>
            </div>
        </div>
        <div class="panel-footer">
            <button id="module_form_submit_btn" class="btn btn-default pull-right" name="submit_{$mod}" value="save" type="submit">
                <i class="process-icon-save"></i>
                {l s="Save" mod={$mod}}
            </button>
            {if $authorization}
                <button id="module_form_submit_btn" class="btn btn-default pull-right" name="submit_{$mod}" value="update_form" type="submit">
                    <i class="process-icon-download"></i>
                    {l s="Update products"}
                </button>
            {/if}
        </div>
    </div>
</form>