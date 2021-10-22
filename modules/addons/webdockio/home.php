<h3>List of Servers</h3>
<table class="table">
    <tr>
        <td>Location / Name</td>
        <td>Date Created</td>
        <td>IPv4</td>
        <td>Status</td>
        <td>Action</td>
    </tr>
    <tr>
        <?php
        foreach ($serverList->getResponse()->toArray() as $server) {
        ?>
    <tr>
        <td><?php echo $server['location'] . ' ' . $server['name']; ?></td>
        <td><?php echo $server['date']; ?></td>
        <td><?php echo $server['ipv4']; ?></td>
        <td><?php echo $server['status']; ?></td>
        <td><a href="#" class="btn btn-primary" onclick="syncPopup('<?php echo $server['slug']; ?>');">Sync</a></td>
    </tr>
<?php
        }
?>
</tr>
</table>

<!-- The Modal -->
<div id="myModal" class="modal">

    <!-- Modal content -->
    <div class="modal-content">
        <span class="close">&times;</span>
        <p id="htmlid">

        </p>
    </div>

</div>

<script>
    function syncPopup(slug) {
        $("#htmlid").html("Please wait...");
        $.ajax({
            url: "addonmodules.php?module=webdockio&ajax=1&slug=" + slug,
            success: function(result) {
                $("#htmlid").html(result);
            }
        });
        document.getElementById("myModal").style.display = "block";
    }

    function assignVps() {
        var vpsslug = jQuery("#vpsslug").val();
        $.ajax({
            url: "addonmodules.php?module=webdockio&ajax=1&slug=" + vpsslug + "&userid=" + jQuery("#userid").val() + "&pid=" + jQuery("#userid").val(),
            success: function(result) {
                $("#responseid").html(result);
            }
        });
    }
    var modal = document.getElementById("myModal");
    var btn = document.getElementById("myBtn");
    var span = document.getElementsByClassName("close")[0];
    span.onclick = function() {
        modal.style.display = "none";
    }
    window.onclick = function(event) {
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }
</script>
<style>
    /* The Modal (background) */
    .modal {
        display: none;
        position: fixed;
        z-index: 10;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgb(0, 0, 0);
        background-color: rgba(0, 0, 0, 0.4);
    }

    /* Modal Content/Box */
    .modal-content {
        background-color: #fefefe;
        margin: 15% auto;
        /* 15% from the top and centered */
        padding: 20px;
        border: 1px solid #888;
        width: 50%;
        /* Could be more or less, depending on screen size */
    }

    /* The Close Button */
    .close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
    }

    .close:hover,
    .close:focus {
        color: black;
        text-decoration: none;
        cursor: pointer;
    }
</style>
