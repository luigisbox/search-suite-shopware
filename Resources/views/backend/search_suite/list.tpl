{extends file="parent:backend/_base/layout.tpl"}

{block name="content/main"}
    <div class="form-group">
        <label for="lb-logs">Logs</label>
        <code>
            <textarea name="lb-logs" id="lb-logs" rows="10" class="form-control" disabled>
                {if ($logs)}
                    {$logs}
                {/if}
            </textarea>
        </code>
    </div>
{/block}
