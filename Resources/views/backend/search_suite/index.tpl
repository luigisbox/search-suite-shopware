{extends file="parent:backend/_base/layout.tpl"}

{block name="content/main"}
    <div class="form-group">
        <a href="{url controller="SearchSuite" action="list" __csrf_token=$csrfToken}" class="btn btn-primary"
           id="index-btn">
            Start indexing
            <div class="spinner-grow spinner-grow-sm ml-2 fade" role="status" id="spinner">
                <span class="sr-only">Loading...</span>
            </div>
        </a>
    </div>
{/block}

{block name="content/scripts"}
    <script>
        const btn = document.getElementById('index-btn');
        const spinner = document.getElementById('spinner');

        btn.onclick = (e) => {
            spinner.classList.add('show');
            btn.classList.add('disabled');
        }
    </script>
    <style>
        #spinner {
            display: none;
        }

        #spinner.show {
            display: inline-block;
            opacity: 1;
        }
    </style>
{/block}
