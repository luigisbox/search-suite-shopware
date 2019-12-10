{extends file="parent:frontend/index/header.tpl"}


{block name="frontend_index_header_javascript_tracking"}
    {$smarty.block.parent}

    {if $luigisBoxScriptUrl}
        <script async src="{$luigisBoxScriptUrl}"></script>
    {/if}
{/block}
