{if $data}
    {foreach from=$data item=item}
        {$item.name}:
        {if $item.error}
            {l s="{$item.error}" mod=$mod}
        {else}
            {foreach from=$item.licenses item=license}
                {$license.number};
            {/foreach}
        {/if}
        <br>
    {/foreach}
{/if}