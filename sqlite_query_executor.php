<?
/* ---- Настройки ---- */
const BaseName = 'base.sql';		//Имя файла с базой
const UserName = 'em';				//Имя для HTTP-авторизации
const Password = 'am';				//Пароль для того же

const AppName = 'SQLite manager';	//Название в поле входа и заголовке окна
$AddRowidToSelect = true;			//По умолчанию добавлять или не добавлять поле rowid к select-запросам		
/* ---- ********* ---- */

mb_internal_encoding('UTF-8');

if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])
	|| $_SERVER['PHP_AUTH_USER'] != UserName || $_SERVER['PHP_AUTH_PW'] != Password)
{
	header('WWW-Authenticate: Basic realm="'.AppName.'"');
	exit('Нужно ввести логин и пароль');
}

function z($text) { return htmlspecialchars($text, ENT_COMPAT, 'UTF-8'); }

//Этот параметр приходит из формы и позволяет не включать rowid в запрос, есил запрос сложный,
//например, с вложенными select
if (!isset($_GET['rowid']))
	$AddRowidToSelect = false;
else
	unset($_GET['rowid']);

try
{
	$sql = new PDO('sqlite:'.BaseName);
	if (!isset($_GET['q']))
	{
		$OriginalRequest = $request = 'SELECT * FROM sqlite_master ORDER BY type DESC, name';
	}
	else	//q — основной запрос без where
	{
		//$request затем может модифицироваться
		$request = $OriginalRequest = $_GET['q'];
	}

	//Определим имя запрашиваемой таблицы и тип запроса
	$TableName = '[master]';		//Переменная для вывода имени запрошенной таблицы
	$RequestType = preg_match('#^\s*([\S]+)#iu', $request, $RT) ? mb_strtolower($RT[1]) : '';		//Тип запроса определяется по его первому слову
	if (preg_match('#^\s*(update|select|insert|delete|replace)#iu', $request))
	{
		if (preg_match('#(?:from|into|^update)\s+\b(.+?)\b#iu', $request, $tmp))
			$TableName = $tmp[1];
		if ($TableName == 'sqlite_master')
			$TableName = '[master]';
	}
	//Отдельно для alter
	if (preg_match('#^\s*alter\s+table\s+\b(.+?)\b#ui', $request, $tmp))
		$TableName = $tmp[1];

	//Если включен такой параметр, добавляем в каждый select поле rowid, если его там ещё нет
	if ($AddRowidToSelect && $RequestType == 'select')
	{
		preg_match_all('#(^\s*select\s+|union\s+select\s+)(.+?)(\s+FROM\s+\b.+?\b)#iu', $request, $selparts, PREG_SET_ORDER);
		foreach ($selparts as &$sp)
		{
			$fields = explode(',', $sp[2]);
			$tmp = $fields;
			array_walk($tmp, function(&$e) {
				$e = str_replace('`', '', trim($e));
				if (preg_match('#\sAS\s+(.+)#ui', $e, $alias))
				{
					if (mb_strtolower($alias[1]) == 'rowid')
						$e = 1;
					return;
				}
				$e = mb_strtolower($e);
		
				$e = (int) ($e == 'rowid' || $e == 'oid' || $e == '_rowid_');
			});
			
			if (array_sum($tmp) == 0)
				array_unshift($fields, 'rowid');
			$sp[2] = implode(', ', $fields);
			$request = str_replace($sp[0], $sp[1].$sp[2].$sp[3], $request);
		}
	}

	//В GET пришла сортировка
	if (isset($_GET['order']))
	{
		$orderValue = rawurldecode($_GET['order']);
		if (preg_match('#^(.+order\s+by\s+)(.+?)(\s+limit\s+[0-9]+(?:\s*,\s*[0-9]+)?)?$#iu', $request, $parts))
		{
			$limit = isset($parts[3]) ? $parts[3] : '';
			$request = $parts[1].$orderValue.$limit;
		}
		else if (preg_match('#^(.+)(\s+limit\s+[0-9]+(?:\s*,\s*[0-9]+)?)#ui', $request, $parts))
			$request = $parts[1].' ORDER BY '.$orderValue.$parts[2];
		else
			$request .= ' ORDER BY '.$orderValue;
	}


	$query = $sql->prepare($request);
	if ($query === false)
	{
		$er = $sql->errorInfo();
		throw new Exception("Ошибка обработки запроса: $request → {$er[2]}");
	}
	$query->execute();

	$er = $query->errorInfo();
	if ($er[0] != '00000')
		throw new Exception($er[2]);
	else
	{
		if (preg_match('#^\s*delete\s+from\s+(.+?)\b#iu', $OriginalRequest, $tbl))
		{
			header('Location: ?q='.rawurlencode('select * from '.$tbl[1]));
			exit('wt' . $OriginalRequest);
		}

		$data = $query->fetchAll(PDO::FETCH_ASSOC);
	}
}
catch (Exception $e)
{
	$error_string = $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?=AppName.': '.BaseName ?></title>
<style>
	* { box-sizing: border-box; }
	body {
		background-color: #222228;
		font-size: 16px;
		color: #FFF;
	}
	a {
		text-decoration: none;
	}
	input {
		padding: 4px 8px;
		height: 32px;
		-webkit-appearance: none;
		-moz-appearance: none;
		border: 1px solid #FFF;
		background-image: linear-gradient(to bottom, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0.1));
		background-color: transparent;
		color: #fff;
		font-size: inherit;
	}
	input[name=rowid] {
		margin: 0 3px;
		vertical-align: middle;
		height: 16px;
		width: 16px;
	}
	input[name=TablesList] { margin-left: 32px; }
	[name=q] { width: 400px; }
	[name=q]:focus { background-image: none; background-color: #222; }
	[type=button], [type=submit] { min-width: 60px; }
	[type=button]:hover, [type=submit]:hover {
		cursor: pointer;
		background-color: #222;
		background-image: none;
	}

	.tablename { padding: 2px 0 8px 0; }
	.tablename a {
		display: inline-block;
		color: #FFEAFF;
		font-weight: bold;
		margin-top: 8px;
	}
	.tablename a:hover { padding: 0 2px; background-color: rgba(255, 100, 00, 0.3); }

	table {
		border-collapse: collapse;
		margin-top: 24px;
	}
	td, th {
		border: 1px solid #888;
		padding: 4px 5px;
		vertical-align: top;
		color: #DDD;
		white-space: pre-wrap;
	}
	thead th {
		font-weight: bold;
		background-color: #101020;
		cursor: pointer;
	}

	.link { cursor: pointer; }
	.blue { color: #2fbbfc; }
	.yellow { color: #fffa94; }
	.grey { color: #939393;; }
	.lightyellow { color: #b0b795; }
	.green { color: #bcf796; }
	.lightgreen { color: #bbddc1; }
	.red { color: #c67788; }
	.lightred { color: #fbb; }
	.violet { color: #7c73cf; }
	.orange { color: #cb742b; }
	
	.highlight {
		color: #fff;
		text-shadow: 0 0 6px rgba(255, 80, 80, 0.7), 0 0 3px rgba(255, 100, 100, 1);
	}
</style>
<script>
	function qs(selector) {
		const elem = (arguments.length > 1 ? arguments[1] : document);
		return elem.querySelector(selector);
	}
	function qsa(selector) {
		const elem = (arguments.length > 1 ? arguments[1] : document);
		return elem.querySelectorAll(selector);
	}
	function onEvent(elem, event, callback) {
		elem.addEventListener(event, function(ev) { callback.call(this, ev) }, false);
	}

	const TableName = '<?=$TableName ?>',
	Rowid = localStorage.getItem('SQLM.Rowid') || false; 

	function ViewTable(name) {
		location.search = '?q=select * from ' + name + (Rowid ? '&rowid=on' : '');
	}

	onEvent(document, 'DOMContentLoaded', () => {
	
		qs('[name=rowid]').checked = Rowid == 'true';

		//Щелчок по заголовку столбца для сортировки по нему. Shift — сразу DESC
		qsa('table th').forEach(el => {
			onEvent(el, 'click', (ev) => {
				let gets = location.search.split('&'),
					elName = el.getAttribute('data-name'),
					asc = !ev.shiftKey;

				gets = gets.map(gt => {
					if (!/^order=/i.test(gt)) return gt;
					//Если сортировка по этому элементу уже есть, нужно изменить порядок сортировки
					if (gt.split('=')[1] == encodeURIComponent(elName))
						asc = false;				//По возрастанию или убыванию

					return null;
				});
				gets.push('order=' + encodeURIComponent(elName + (!asc ? ' desc' : '')));
				const getLine = gets.reduce((prev, curr) => prev + (curr == null ? '' : '&' + curr), '').replace(/^&/, '');

				location.search = getLine;
			});
		});

		onEvent(qs('[name=rowid]'), 'change', function(ev) { localStorage.setItem('SQLM.Rowid', this.checked); qs('#RequestForm').submit(); });

		//Действия для страницы со списком таблиц
		if (TableName == '[master]')
		{
			let typeCol = nameCol = sqlCol = null;
			const headCols = qsa('table th'),
			      bodyRows = qsa('table tbody tr');

			//Ищем индексы в строке для каждого столбца
			headCols.forEach((cl, index) => {
				const clName = cl.getAttribute('data-name');
				switch (clName)
				{
					case 'type': typeCol = index; break;
					case 'name': nameCol = index; break;
					case  'sql': sqlCol  = index; break;
				}
			});

			//Теперь раскрашиваем найденные поля
			if (typeCol !== null)
			{
				bodyRows.forEach(el => {
					const td = qsa('td', el);
					const typeValue = td[typeCol].textContent;
	
					if (typeValue == 'table')
					{
						td[typeCol].classList.add('yellow');
						if (nameCol !== null)
						{
							td[nameCol].classList.add('blue', 'link');
							td[nameCol].setAttribute('data-tablename', '');
						}
					}
					else if (typeValue == 'index')
					{
						td[typeCol].classList.add('lightyellow');
						if (nameCol !== null)
							td[nameCol].classList.add('grey');
					}
					
					if (sqlCol !== null)
					{
						const tableNameColor = 'blue';
						let sqlString = td[sqlCol].textContent,
						    CT,
						    nameColor,
						    constrColor;
						const rg = new RegExp('(create\\s+' + typeValue + '\\s+)(.+?)\\b', 'i'),
						      rgShort = new RegExp('(create\\s+' + typeValue + ')', 'gi');

						if (CT = sqlString.match(rg))
						{
							if (typeValue == 'index')
							{
								constrColor = 'lightgreen';			//Цвет CREATE INDEX
								nameColor = 'grey';						//Цвет имени индекса
								sqlString = sqlString.replace(CT[0], CT[1] + '<span class="' + nameColor + '">' + CT[2] + '</span>')
								            .replace(rgShort, '<span class="' + constrColor + '">$1</span>');
								sqlString = sqlString.replace(/(on\s+)(.+?)\b/i, '$1<span class="link ' + tableNameColor + '" data-tablename="">$2</span>');
							}
							else
							{
								constrColor = 'green';					//Цвет CREATE TABLE
								nameColor = tableNameColor;			//Цвет имени таблицы
								sqlString = sqlString.replace(CT[0], CT[1] + '<span class="link ' + nameColor + '" data-tablename="">' + CT[2] + '</span>')
								            .replace(rgShort, '<span class="' + constrColor + '">$1</span>');
							
								const varColor = 'orange',				//Цвет поля таблицы
								      typeColor = 'yellow',			//Цвет типа поля
								      defaultsColor = 'violet';		//Цвет значения DEFAULT
								sqlString = sqlString.replace(/((?:\(|,))\s*(.+?)(\s+)(.+?)\b/ig, '$1 <span class="' + varColor + '">$2</span>$3<span class="' + typeColor + '">$4</span>');
								sqlString = sqlString.replace(/(default\s+.+?)\s*(?=,|\))/ig, '<span class="' + defaultsColor + '">$1</span>');
								sqlString = sqlString.replace(/\s*\)/g, ' )');
							}
						}

						td[sqlCol].innerHTML = sqlString.replace(/([\(\)])/g, '<span class="red">$1</span>');
					}
				});

				qsa('table [data-tablename]').forEach(el => {
					onEvent(el, 'click', ev => {
						ViewTable(el.textContent);
					});
					onEvent(el, 'mouseover', ev => {
						const thisValue = el.textContent;
						qsa('table [data-tablename]').forEach(el => {
							if (el.textContent == thisValue)
								el.classList.add('highlight');
						});
					});
					onEvent(el, 'mouseout', ev => {
						const thisValue = el.textContent;
						qsa('table [data-tablename]').forEach(el => {
							if (el.textContent == thisValue)
								el.classList.remove('highlight');
						});
					});
				});
			}
		}
		else
		{
			//В остальных таблицах просто отметим столбец rowid
			let rowidIndex = null;
			const headCols = qsa('table th'),
			      bodyRows = qsa('table tbody tr');

			headCols.forEach((cl, index) => {
				if (cl.getAttribute('data-name') == 'rowid')
					rowidIndex = index;
			});
			if (rowidIndex !== null)
			{
				bodyRows.forEach(el => {
					qsa('td', el)[rowidIndex].classList.add('blue');
				});
			}
		}
	});
</script>
</head>
<body>
<? $query = empty($OriginalRequest) ? '' : $OriginalRequest; ?>
	<form action="?" method="get" id="RequestForm">
	<input type="text" value="<?=$query?>" name="q"> <input type="submit" id="MakeQuery" value="&gt;&gt;"> <label><input name="rowid" type="checkbox" checked title="Добавлять rowid"> Добавить rowid</label> <input type="button" name="TablesList" onclick="location.search=''" value="Список таблиц" title="Отобразить список таблиц">
	</form>
<?
if (!empty($error_string))
	echo "<h3>{$error_string}</h3>";
else
{
	if ($TableName != '[master]')
	{
		echo '<div class="tablename">Таблица [ <a href="#" onclick="ViewTable(\''.$TableName.'\')" title="Активная таблица">'.$TableName.'</a> ]</div>';
	}
	
	if (!empty($data))
	{
		echo '<table><thead><tr>';
		foreach ($data[0] as $name => $val)
			echo '<th data-name="'.z($name).'">'.z($name).'</th>';
		echo '</tr></thead><tbody>';
		foreach ($data as $line)
		{
			echo '<tr>';
			foreach ($line as $name => $val)
				echo '<td>'.z($val).'</td>';
			echo "</tr>\n";
		}
		echo '</tbody></table>';
	}
}?>
</body>
</html>