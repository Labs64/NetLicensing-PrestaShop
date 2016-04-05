<div class="row">
    <div class="col-lg-7">
        <div class="panel {$mod}-panel">
            <div class="panel-heading">
                <i class="icon-check"></i>
                {l s="License" mod=$mod}
                <span class="badge">{$count}</span>
            </div>
            <div class="well">
                <form action="{$action}" method="post">
                    {if $send_email}
                        <button class="btn btn-default" name="submit_{$mod}_order" type="submit" value="send_email">
                            <i class="icon-envelope"></i>
                            {l s="Send Email"}
                        </button>
                    {/if}
                    <button class="btn btn-default" name="submit_{$mod}_order" type="submit" value="check_state">
                        <i class="icon-refresh"></i>
                        {l s="Check state"}
                    </button>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                    <tr>
                        <th></th>
                        <th><span class="title_box">{l s="Product"}</span></th>
                        <th><span class="title_box">{l s="Licenses"}</span></th>
                        <th><span class="title_box">{l s="State"}</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    {if $data}
                        {foreach from=$data item=item}
                            <tr class="product-licenses-row {$item.licensing_type} {$item.licensing_model}">
                                <td>{if $item.image_url}<img src="{$item.image_url}">{/if}
                                </td>
                                <td>{if $item.name}{$item.name}{/if}</td>
                                <td>
                                    {if $item.error}
                                        <div class="alert alert-danger">{l s="{$item.error}" mod=$mod}</div>
                                    {else}
                                        <table class="table">
                                            <tbody>
                                            {foreach from=$item.licenses item=license}
                                                <tr>
                                                    <td>{$license.number}</td>
                                                </tr>
                                            {/foreach}
                                            </tbody>
                                        </table>
                                    {/if}
                                </td>
                                <td>
                                    {if !$item.error}
                                        <table class="table">
                                            <tbody>
                                            {foreach from=$item.licenses item=license}
                                                <tr>
                                                    <td>{if $license.active}
                                                            <span class="license-active">
                                                                {l s="Active"}
                                                            </span>
                                                        {else}
                                                            <span class="license-inactive">
                                                                {l s="Inactive"}
                                                            </span>
                                                        {/if}
                                                    </td>
                                                </tr>
                                            {/foreach}
                                            </tbody>
                                        </table>
                                    {/if}
                                </td>
                            </tr>
                        {/foreach}
                    {/if}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
