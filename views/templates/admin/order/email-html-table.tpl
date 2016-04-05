<table>
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
            <tr>
                <td>{if $item.image_url}<img src="{$item.image_url}">{/if}
                </td>
                <td>{if $item.name}{$item.name}{/if}</td>
                <td>
                    {if $item.error}
                        <div style="color: red">{l s="{$item.error}" mod=$mod}</div>
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
                                            <span style="color: green">{l s="Active"}</span>
                                        {else}
                                            <span style="color: red">{l s="Inactive"}</span>
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
