<table class="table">
    <tr>
            <td>Name</td><td>{$vps.name}</td>
    </tr>
    <tr>
            <td>Date</td><td>{$vps.date}</td>
    </tr>
    <tr>
            <td>Image</td><td>{$vps.image}</td>
    </tr>
    <tr>
            <td>IPV4</td><td>{$vps.ipv4}</td>
    </tr>
       <tr>
            <td>IPV6</td><td>{$vps.ipv6}</td>
    </tr>
    <tr>
            <td>Status</td><td>{$vps.status}</td>
    </tr> 
    <tr>
            <td>Aliases</td><td>{foreach from=$vps.aliases item=aliase}
                {$aliase}<br>
                {/foreach}
            </td>
    </tr>  

    <tr>
        <td>Reinstall</td>
        <td>
                <form method="post" action="clientarea.php?action=productdetails">
                <input type="hidden" name="id" value="{$serviceid}" />
                <input type="hidden" name="modop" value="custom" />
                <input type="hidden" name="a" value="reinstall" />
                <input type="submit" value="Reinstall VPS Server" />
                </form>
        </td>
    </tr>  
</table>