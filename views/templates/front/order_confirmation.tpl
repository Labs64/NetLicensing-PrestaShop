{if !empty($data)}
    <div id="nlic-order-confirmation">
        <table class="table table-bordered">
            <tr>
                <th>{l s="Product"}</th>
                <th>{l s="Name"}</th>
                <th>{l s="License"}</th>
            </tr>
            {foreach from=$data item="unit"}
                <tr>
                    <td>{if !empty($unit.product.image)}<img alt="{$unit.product.name}" src="{$unit.product.image}">{/if}</td>
                    <td>{$unit.product.name}</td>
                    <td>
                        {foreach from=$unit.licenses item="license"}
                            <p>{$license}</p>
                        {/foreach}
                    </td>
                </tr>
            {/foreach}
        </table>
    </div>
{/if}
