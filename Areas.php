<?php
$title = "Sales Area Maintenance";

$PageSecurity = 3;

include("includes/session.inc");
include("includes/header.inc");

?>

<P>

<?php

if (isset($_GET['SelectedArea'])){
	$SelectedArea = strtoupper($_GET['SelectedArea']);
} elseif (isset($_POST['SelectedArea'])){
	$SelectedArea = strtoupper($_POST['SelectedArea']);
}

if ($_POST['submit']) {

	//initialise no input errors assumed initially before we test
	$InputError = 0;

	/* actions to take once the user has clicked the submit button
	ie the page has called itself with some user input */

	//first off validate inputs sensible
	$_POST["AreaCode"] = strtoupper($_POST["AreaCode"]);
	if (strlen($_POST['AreaCode']) > 2) {
		$InputError = 1;
		echo "The area code must be two characters or less long";
	} elseif (strlen($_POST['AreaDescription']) >50) {
		$InputError = 1;
		echo "The area description must be fifty characters or less long";
	}

	if ($SelectedArea AND $InputError !=1) {

		/*SelectedArea could also exist if submit had not been clicked this code would not run in this case cos submit is false of course  see the delete code below*/

		$sql = "UPDATE Areas SET AreaCode='" . $_POST['AreaCode'] . "', AreaDescription='" . $_POST['AreaDescription'] . "' WHERE AreaCode = '$SelectedArea'";
		$msg = "Area code $SelectedArea has been updated.";
	} elseif ($InputError !=1) {

	/*Selectedarea is null cos no item selected on first time round so must be adding a record must be submitting new entries in the new area form */

		$sql = "INSERT INTO Areas (AreaCode, AreaDescription) VALUES ('" . $_POST['AreaCode'] . "', '" . $_POST['AreaDescription'] . "')";
		$SelectedArea =$_POST['AreaCode'];
		$msg = "New area code " . $_POST['AreaCode'] . " has been inserted.";
	}

	//run the SQL from either of the above possibilites
	$result = DB_query($sql,$db);
	echo "<BR>$msg";

} elseif (isset($_GET['delete'])) {
//the link to delete a selected record was clicked instead of the submit button

	$CancelDelete = 0;

// PREVENT DELETES IF DEPENDENT RECORDS IN 'DebtorsMaster'

	$sql= "SELECT COUNT(*) FROM CustBranch WHERE CustBranch.Area='$SelectedArea'";
	$result = DB_query($sql,$db);
	$myrow = DB_fetch_row($result);
	if ($myrow[0]>0) {
		$CancelDelete = 1;
		echo "Cannot delete this area because customer branches have been created using this area.";
		echo "<br> There are " . $myrow[0] . " branches using this area code";

	} else {
		$sql= "SELECT COUNT(*) FROM SalesAnalysis WHERE SalesAnalysis.Area ='$SelectedArea'";
		$result = DB_query($sql,$db);
		$myrow = DB_fetch_row($result);
		if ($myrow[0]>0) {
			$CancelDelete = 1;
			echo "Cannot delete this area because sales analysis ecords exist that use this area.";
			echo "<br> There are " . $myrow[0] . " sales analysis records referring this area code";
		}
	}

	if ($CancelDelete==0) {
		$sql="DELETE FROM Areas WHERE AreaCode='" . $SelectedArea . "'";
		$result = DB_query($sql,$db);
		echo "<BR><B>Area Code $SelectedArea has been deleted ! <p>";
	} //end if Delete area
} elseif (!isset($SelectedArea)) {


	$sql = "SELECT * FROM Areas";
	$result = DB_query($sql,$db);

	echo "<CENTER><table border=1>\n";
	echo "<tr><td class='tableheader'>Area Code</td><td class='tableheader'>Area Name</td>\n";

	$k=0; //row colour counter

	while ($myrow = DB_fetch_row($result)) {
		if ($k==1){
			echo "<tr bgcolor='#CCCCCC'>";
			$k=0;
		} else {
			echo "<tr bgcolor='#EEEEEE'>";
			$k++;
		}
		printf("<td>%s</td><td>%s</td><td><a href=\"%sSelectedArea=%s\">Edit</td><td><a href=\"%sSelectedArea=%s&delete=1\">Delete</td></tr>", $myrow[0], $myrow[1], $_SERVER['PHP_SELF'] . "?" . SID, $myrow[0], $_SERVER['PHP_SELF'] . "?" . SID, $myrow[0]);

	}
	//END WHILE LIST LOOP
}

//end of ifs and buts!

?>
</CENTER></table>
<p>
<?php
if (isset($SelectedArea)) {  ?>
	<Center><a href="<?php echo $_SERVER['PHP_SELF'] . "?" . SID;?>">Review Areas Defined</a></Center>
<?php } ?>

<P>


<?php



if (!isset($_GET['delete'])) {

	echo "<FORM METHOD='post' action=" . $_SERVER['PHP_SELF'] . "?" . SID . ">";

	if ($SelectedArea) {
		//editing an existing area

		$sql = "SELECT AreaCode, AreaDescription FROM Areas WHERE AreaCode='$SelectedArea'";

		$result = DB_query($sql, $db);
		$myrow = DB_fetch_array($result);

		$_POST['AreaCode'] = $myrow["AreaCode"];
		$_POST['AreaDescription']  = $myrow["AreaDescription"];

		echo "<INPUT TYPE=HIDDEN NAME=SelectedArea VALUE=" . $SelectedArea . ">";
		echo "<INPUT TYPE=HIDDEN NAME=AreaCode VALUE=" .$_POST['AreaCode'] . ">";
		echo "<CENTER><TABLE><TR><TD>Area Code:</TD><TD>" . $_POST['AreaCode'] . "</TD></TR>";

	} else {
		echo "<CENTER><TABLE><TR><TD>Area Code:</TD><TD><input type='Text' name='AreaCode' value='" . $_POST['AreaCode'] . "' SIZE=3 MAXLENGTH=2></TD></TR>";
	}
	?>

	<TR><TD>Area Name:</TD>
	<TD><input type="Text" name="AreaDescription" value="<?php echo $_POST['AreaDescription']; ?>" SIZE=26 MAXLENGTH=25></TD></TR>

	</TABLE>

	<CENTER><input type="Submit" name="submit" value="Enter Information">

	</FORM>

<?php } //end if record deleted no point displaying form to add record 

include("includes/footer.inc");
?>
