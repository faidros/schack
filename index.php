<?php
session_start();

// Debug-loggning
ini_set('error_log', '/Users/micke/code/schack2/debug.log');
ini_set('log_errors', 1);

// Initiera nytt spel om det inte finns eller om reset begärs
if (!isset($_SESSION['game']) || isset($_GET['reset'])) {
    $_SESSION['game'] = [
        'board' => initializeBoard(),
        'currentPlayer' => 'white',
        'selectedSquare' => null,
        'gameOver' => false,
        'winner' => null,
        'result' => null, // 'checkmate', 'stalemate', etc.
        'lastMove' => null, // För en passant
        'moveHistory' => [], // För rockad
        'enPassantTarget' => null // Rutan där en passant kan ske
    ];
    
    // Hantera spelläge (mot AI eller 2 spelare)
    if (isset($_GET['mode'])) {
        $_SESSION['gameMode'] = $_GET['mode']; // 'ai' eller '2player'
        $_SESSION['game_started'] = true; // Markera att spelet har startat
    } elseif (!isset($_SESSION['gameMode'])) {
        $_SESSION['gameMode'] = '2player';
    }
    
    // Hantera färgval
    if (isset($_GET['color'])) {
        $_SESSION['playerColor'] = $_GET['color'];
    } elseif (!isset($_SESSION['playerColor'])) {
        $_SESSION['playerColor'] = 'random';
    }
    
    // Slumpa färg om valt
    if ($_SESSION['playerColor'] === 'random') {
        $_SESSION['playerColor'] = (rand(0, 1) === 0) ? 'white' : 'black';
    }
    
    // Om AI-läge och AI spelar vit, gör AI:s första drag
    if (isset($_SESSION['gameMode']) && $_SESSION['gameMode'] === 'ai' && $_SESSION['playerColor'] === 'black') {
        makeAIMove();
    }
    
    // Omdirigera för att ta bort reset-parametern från URL:en
    if (isset($_GET['reset']) || isset($_GET['mode'])) {
        header('Location: index.php');
        exit;
    }
}

// Hantera drag
if (isset($_POST['move'])) {
    $moveData = json_decode($_POST['move'], true);
    error_log("Move received: " . print_r($moveData, true));
    if ($moveData) {
        $success = makeMove($moveData['from'], $moveData['to']);
        error_log("Move success: " . ($success ? 'YES' : 'NO'));
        
        // Om AI-läge, låt AI göra sitt drag efter spelarens drag
        if ($success && isset($_SESSION['gameMode']) && $_SESSION['gameMode'] === 'ai' && 
            !$_SESSION['game']['gameOver']) {
            // Lägg till en liten paus så draget är synligt
            usleep(100000); // 0.1 sekund
            makeAIMove();
            error_log("AI move completed");
        }
    }
}

function initializeBoard() {
    $board = array_fill(0, 8, array_fill(0, 8, null));
    
    // Svarta pjäser
    $board[0] = ['r', 'n', 'b', 'q', 'k', 'b', 'n', 'r'];
    $board[1] = array_fill(0, 8, 'p');
    
    // Vita pjäser
    $board[6] = array_fill(0, 8, 'P');
    $board[7] = ['R', 'N', 'B', 'Q', 'K', 'B', 'N', 'R'];
    
    return $board;
}

function makeMove($from, $to) {
    $board = &$_SESSION['game']['board'];
    $piece = $board[$from[0]][$from[1]];
    
    error_log("Making move from [" . $from[0] . "," . $from[1] . "] to [" . $to[0] . "," . $to[1] . "] with piece: " . ($piece ?? 'NULL'));
    
    if ($piece && isValidMove($from, $to, $piece)) {
        $isWhitePiece = ctype_upper($piece);
        $pieceType = strtolower($piece);
        
        // Hantera en passant
        if ($pieceType === 'p' && $_SESSION['game']['enPassantTarget']) {
            $epTarget = $_SESSION['game']['enPassantTarget'];
            if ($to[0] === $epTarget[0] && $to[1] === $epTarget[1]) {
                // Ta bort den tagna bonden
                $captureRow = $isWhitePiece ? $to[0] + 1 : $to[0] - 1;
                $board[$captureRow][$to[1]] = null;
            }
        }
        
        // Hantera rockad
        if ($pieceType === 'k' && abs($to[1] - $from[1]) === 2) {
            // Kort rockad (O-O)
            if ($to[1] === 6) {
                $board[$from[0]][5] = $board[$from[0]][7]; // Flytta torn
                $board[$from[0]][7] = null;
            }
            // Lång rockad (O-O-O)
            else if ($to[1] === 2) {
                $board[$from[0]][3] = $board[$from[0]][0]; // Flytta torn
                $board[$from[0]][0] = null;
            }
        }
        
        // Flytta pjäsen
        $board[$to[0]][$to[1]] = $piece;
        $board[$from[0]][$from[1]] = null;
        
        // Hantera bonde-promovering
        if ($pieceType === 'p') {
            $promotionRow = $isWhitePiece ? 0 : 7;
            if ($to[0] === $promotionRow) {
                // Promoera till drottning automatiskt
                $board[$to[0]][$to[1]] = $isWhitePiece ? 'Q' : 'q';
            }
        }
        
        // Sätt en passant-mål om bonden flyttade två steg
        $_SESSION['game']['enPassantTarget'] = null;
        if ($pieceType === 'p' && abs($to[0] - $from[0]) === 2) {
            $epRow = $isWhitePiece ? $from[0] - 1 : $from[0] + 1;
            $_SESSION['game']['enPassantTarget'] = [$epRow, $from[1]];
        }
        
        // Spara drag i historiken
        $_SESSION['game']['lastMove'] = ['from' => $from, 'to' => $to, 'piece' => $piece];
        if (!isset($_SESSION['game']['moveHistory'])) {
            $_SESSION['game']['moveHistory'] = [];
        }
        
        // Formatera draget för visning
        $fromNotation = chr(97 + $from[1]) . (8 - $from[0]); // a-h, 1-8
        $toNotation = chr(97 + $to[1]) . (8 - $to[0]);
        $moveNotation = $fromNotation . '-' . $toNotation;
        
        $_SESSION['game']['moveHistory'][] = [
            'from' => $from, 
            'to' => $to, 
            'piece' => $piece,
            'notation' => $moveNotation,
            'player' => $currentColor
        ];
        
        // Kontrollera om draget sätter egen kung i schack
        $currentColor = $_SESSION['game']['currentPlayer'];
        if (isKingInCheck($currentColor)) {
            // Ogiltigt drag - återställ
            $board[$from[0]][$from[1]] = $piece;
            $board[$to[0]][$to[1]] = null;
            error_log("Move puts own king in check - invalid");
            return false;
        }
        
        // Byt spelare
        $_SESSION['game']['currentPlayer'] = 
            $_SESSION['game']['currentPlayer'] === 'white' ? 'black' : 'white';
        
        // Kontrollera schack, schackmatt och patt för motståndaren
        $opponentColor = $_SESSION['game']['currentPlayer'];
        if (isKingInCheck($opponentColor)) {
            error_log("Opponent king is in check!");
            if (isCheckmate($opponentColor)) {
                $_SESSION['game']['gameOver'] = true;
                $_SESSION['game']['winner'] = $currentColor;
                $_SESSION['game']['result'] = 'checkmate';
                error_log("CHECKMATE! Winner: " . $currentColor);
            }
        } else {
            // Inte i schack, kontrollera patt
            if (isStalemate($opponentColor)) {
                $_SESSION['game']['gameOver'] = true;
                $_SESSION['game']['winner'] = null; // Oavgjort
                $_SESSION['game']['result'] = 'stalemate';
                error_log("STALEMATE! Game is a draw.");
            }
        }
        
        error_log("Move completed, new player: " . $_SESSION['game']['currentPlayer']);
        return true;
    }
    
    error_log("Move validation failed");
    return false;
}

function isValidMove($from, $to, $piece) {
    $board = $_SESSION['game']['board'];
    $currentPlayer = $_SESSION['game']['currentPlayer'];
    
    // Kontrollera att det är rätt spelares tur
    $isWhitePiece = ctype_upper($piece);
    if (($isWhitePiece && $currentPlayer !== 'white') || 
        (!$isWhitePiece && $currentPlayer !== 'black')) {
        return false;
    }
    
    // Kontrollera att målrutan inte har egen pjäs
    $targetPiece = $board[$to[0]][$to[1]];
    if ($targetPiece) {
        $targetIsWhite = ctype_upper($targetPiece);
        if ($isWhitePiece === $targetIsWhite) {
            return false;
        }
    }
    
    // Grundläggande validering (förenklad)
    $rowDiff = abs($to[0] - $from[0]);
    $colDiff = abs($to[1] - $from[1]);
    
    $pieceType = strtolower($piece);
    
    switch ($pieceType) {
        case 'p': // Bonde
            $direction = $isWhitePiece ? -1 : 1;
            $startRow = $isWhitePiece ? 6 : 1;
            
            // Framåt ett steg
            if ($from[1] === $to[1] && $to[0] === $from[0] + $direction && !$targetPiece) {
                return true;
            }
            // Framåt två steg från startposition
            if ($from[1] === $to[1] && $from[0] === $startRow && 
                $to[0] === $from[0] + (2 * $direction) && !$targetPiece &&
                !$board[$from[0] + $direction][$from[1]]) { // Vägen måste vara fri
                return true;
            }
            // Ta diagonalt
            if ($colDiff === 1 && $to[0] === $from[0] + $direction && $targetPiece) {
                return true;
            }
            // En passant
            if ($colDiff === 1 && $to[0] === $from[0] + $direction && !$targetPiece) {
                $epTarget = $_SESSION['game']['enPassantTarget'] ?? null;
                if ($epTarget && $to[0] === $epTarget[0] && $to[1] === $epTarget[1]) {
                    return true;
                }
            }
            return false;
            
        case 'r': // Torn
            return ($rowDiff === 0 || $colDiff === 0) && isPathClear($from, $to);
            
        case 'n': // Springare
            return ($rowDiff === 2 && $colDiff === 1) || ($rowDiff === 1 && $colDiff === 2);
            
        case 'b': // Löpare
            return ($rowDiff === $colDiff && $rowDiff > 0) && isPathClear($from, $to);
            
        case 'q': // Drottning
            return (($rowDiff === 0 || $colDiff === 0) || ($rowDiff === $colDiff && $rowDiff > 0)) 
                   && isPathClear($from, $to);
            
        case 'k': // Kung
            // Normal kungrörelse
            if ($rowDiff <= 1 && $colDiff <= 1) {
                return true;
            }
            // Rockad
            if ($rowDiff === 0 && $colDiff === 2) {
                return canCastle($from, $to, $isWhitePiece);
            }
            return false;
    }
    
    return false;
}

function isPathClear($from, $to) {
    $board = $_SESSION['game']['board'];
    $rowDir = $to[0] > $from[0] ? 1 : ($to[0] < $from[0] ? -1 : 0);
    $colDir = $to[1] > $from[1] ? 1 : ($to[1] < $from[1] ? -1 : 0);
    
    $row = $from[0] + $rowDir;
    $col = $from[1] + $colDir;
    
    while ($row !== $to[0] || $col !== $to[1]) {
        if ($board[$row][$col] !== null) {
            return false;
        }
        $row += $rowDir;
        $col += $colDir;
    }
    
    return true;
}

function findKing($color) {
    $board = $_SESSION['game']['board'];
    $kingPiece = $color === 'white' ? 'K' : 'k';
    
    for ($row = 0; $row < 8; $row++) {
        for ($col = 0; $col < 8; $col++) {
            if ($board[$row][$col] === $kingPiece) {
                return [$row, $col];
            }
        }
    }
    return null;
}

function isSquareUnderAttack($square, $byColor) {
    $board = $_SESSION['game']['board'];
    
    // Spara nuvarande spelare
    $originalPlayer = $_SESSION['game']['currentPlayer'];
    $_SESSION['game']['currentPlayer'] = $byColor;
    
    // Kolla om någon motståndarpjäs kan attackera denna ruta
    for ($row = 0; $row < 8; $row++) {
        for ($col = 0; $col < 8; $col++) {
            $piece = $board[$row][$col];
            if (!$piece) continue;
            
            $isWhitePiece = ctype_upper($piece);
            $pieceColor = $isWhitePiece ? 'white' : 'black';
            
            if ($pieceColor === $byColor) {
                // Använd en förenklad validering för att undvika oändlig loop
                if (canPieceAttack([$row, $col], $square, $piece)) {
                    $_SESSION['game']['currentPlayer'] = $originalPlayer;
                    return true;
                }
            }
        }
    }
    
    $_SESSION['game']['currentPlayer'] = $originalPlayer;
    return false;
}

function canPieceAttack($from, $to, $piece) {
    $board = $_SESSION['game']['board'];
    $isWhitePiece = ctype_upper($piece);
    $pieceType = strtolower($piece);
    
    $rowDiff = abs($to[0] - $from[0]);
    $colDiff = abs($to[1] - $from[1]);
    
    switch ($pieceType) {
        case 'p':
            $direction = $isWhitePiece ? -1 : 1;
            return ($colDiff === 1 && $to[0] === $from[0] + $direction);
            
        case 'r':
            return ($rowDiff === 0 || $colDiff === 0) && isPathClear($from, $to);
            
        case 'n':
            return ($rowDiff === 2 && $colDiff === 1) || ($rowDiff === 1 && $colDiff === 2);
            
        case 'b':
            return ($rowDiff === $colDiff && $rowDiff > 0) && isPathClear($from, $to);
            
        case 'q':
            return (($rowDiff === 0 || $colDiff === 0) || ($rowDiff === $colDiff && $rowDiff > 0)) 
                   && isPathClear($from, $to);
            
        case 'k':
            return $rowDiff <= 1 && $colDiff <= 1;
    }
    
    return false;
}

function isKingInCheck($color) {
    $kingPos = findKing($color);
    if (!$kingPos) return false;
    
    $opponentColor = $color === 'white' ? 'black' : 'white';
    return isSquareUnderAttack($kingPos, $opponentColor);
}

function isCheckmate($color) {
    if (!isKingInCheck($color)) {
        return false;
    }
    
    // Kolla om det finns något giltigt drag som tar bort schacken
    $moves = getAllPossibleMoves($color);
    
    foreach ($moves as $move) {
        // Simulera draget
        $board = &$_SESSION['game']['board'];
        $piece = $board[$move['from'][0]][$move['from'][1]];
        $capturedPiece = $board[$move['to'][0]][$move['to'][1]];
        
        $board[$move['to'][0]][$move['to'][1]] = $piece;
        $board[$move['from'][0]][$move['from'][1]] = null;
        
        $stillInCheck = isKingInCheck($color);
        
        // Återställ draget
        $board[$move['from'][0]][$move['from'][1]] = $piece;
        $board[$move['to'][0]][$move['to'][1]] = $capturedPiece;
        
        if (!$stillInCheck) {
            return false; // Det finns ett drag som räddar kungen
        }
    }
    
    return true; // Schackmatt!
}

function isStalemate($color) {
    // Patt = inte i schack men inga giltiga drag
    if (isKingInCheck($color)) {
        return false; // Står i schack, inte patt
    }
    
    // Kolla om det finns några giltiga drag
    $moves = getAllPossibleMoves($color);
    
    foreach ($moves as $move) {
        // Simulera draget
        $board = &$_SESSION['game']['board'];
        $piece = $board[$move['from'][0]][$move['from'][1]];
        $capturedPiece = $board[$move['to'][0]][$move['to'][1]];
        
        $board[$move['to'][0]][$move['to'][1]] = $piece;
        $board[$move['from'][0]][$move['from'][1]] = null;
        
        $wouldBeInCheck = isKingInCheck($color);
        
        // Återställ draget
        $board[$move['from'][0]][$move['from'][1]] = $piece;
        $board[$move['to'][0]][$move['to'][1]] = $capturedPiece;
        
        if (!$wouldBeInCheck) {
            return false; // Det finns minst ett giltigt drag
        }
    }
    
    return true; // Patt! Inga giltiga drag
}

function canCastle($from, $to, $isWhite) {
    $board = $_SESSION['game']['board'];
    $moveHistory = $_SESSION['game']['moveHistory'] ?? [];
    
    $row = $isWhite ? 7 : 0;
    $kingPiece = $isWhite ? 'K' : 'k';
    
    // Kungen måste vara på sin startposition
    if ($from[0] !== $row || $from[1] !== 4) {
        return false;
    }
    
    // Kontrollera att kungen inte har flyttats tidigare
    foreach ($moveHistory as $move) {
        if ($move['piece'] === $kingPiece) {
            return false;
        }
    }
    
    $isKingSide = $to[1] === 6; // Kort rockad
    $rookCol = $isKingSide ? 7 : 0;
    $rookPiece = $isWhite ? 'R' : 'r';
    
    // Kontrollera att tornet finns och inte har flyttats
    if ($board[$row][$rookCol] !== $rookPiece) {
        return false;
    }
    
    foreach ($moveHistory as $move) {
        if ($move['from'][0] === $row && $move['from'][1] === $rookCol) {
            return false;
        }
    }
    
    // Kontrollera att rutorna mellan kung och torn är tomma
    $startCol = min($from[1], $rookCol) + 1;
    $endCol = max($from[1], $rookCol) - 1;
    
    for ($col = $startCol; $col <= $endCol; $col++) {
        if ($board[$row][$col] !== null) {
            return false;
        }
    }
    
    // Kontrollera att kungen inte står i schack
    $color = $isWhite ? 'white' : 'black';
    $opponentColor = $isWhite ? 'black' : 'white';
    
    if (isKingInCheck($color)) {
        return false;
    }
    
    // Kontrollera att kungen inte passerar genom schack
    $direction = $isKingSide ? 1 : -1;
    for ($col = $from[1]; $col !== $to[1] + $direction; $col += $direction) {
        if (isSquareUnderAttack([$row, $col], $opponentColor)) {
            return false;
        }
    }
    
    return true;
}

function getPieceSymbol($piece) {
    if ($piece === null) return '';
    
    $symbols = [
        'K' => '♔', 'Q' => '♕', 'R' => '♖', 'B' => '♗', 'N' => '♘', 'P' => '♙',
        'k' => '♚', 'q' => '♛', 'r' => '♜', 'b' => '♝', 'n' => '♞', 'p' => '♟'
    ];
    
    return $symbols[$piece] ?? '';
}

function getAllPossibleMoves($color) {
    $board = $_SESSION['game']['board'];
    $moves = [];
    
    // Spara nuvarande spelare temporärt
    $originalPlayer = $_SESSION['game']['currentPlayer'];
    $_SESSION['game']['currentPlayer'] = $color;
    
    for ($row = 0; $row < 8; $row++) {
        for ($col = 0; $col < 8; $col++) {
            $piece = $board[$row][$col];
            if (!$piece) continue;
            
            $isWhitePiece = ctype_upper($piece);
            $pieceColor = $isWhitePiece ? 'white' : 'black';
            
            if ($pieceColor !== $color) continue;
            
            // Testa alla möjliga destinationer
            for ($toRow = 0; $toRow < 8; $toRow++) {
                for ($toCol = 0; $toCol < 8; $toCol++) {
                    if ($row === $toRow && $col === $toCol) continue;
                    
                    if (isValidMove([$row, $col], [$toRow, $toCol], $piece)) {
                        $moves[] = [
                            'from' => [$row, $col],
                            'to' => [$toRow, $toCol],
                            'piece' => $piece
                        ];
                    }
                }
            }
        }
    }
    
    // Återställ nuvarande spelare
    $_SESSION['game']['currentPlayer'] = $originalPlayer;
    
    return $moves;
}

function evaluateMove($move) {
    $board = $_SESSION['game']['board'];
    $score = 0;
    
    // Värdera tagna pjäser
    $targetPiece = $board[$move['to'][0]][$move['to'][1]];
    if ($targetPiece) {
        $pieceValues = [
            'p' => 10, 'n' => 30, 'b' => 30, 
            'r' => 50, 'q' => 90, 'k' => 900
        ];
        $score += $pieceValues[strtolower($targetPiece)] ?? 0;
    }
    
    // Föredra central kontroll
    $centerDistance = abs($move['to'][0] - 3.5) + abs($move['to'][1] - 3.5);
    $score += (7 - $centerDistance) * 2;
    
    // Flytta pjäser framåt tidigt i spelet
    $pieceType = strtolower($move['piece']);
    if ($pieceType === 'p' || $pieceType === 'n' || $pieceType === 'b') {
        $isWhite = ctype_upper($move['piece']);
        $forwardBonus = $isWhite ? (6 - $move['to'][0]) : ($move['to'][0] - 1);
        $score += $forwardBonus;
    }
    
    return $score;
}

function makeAIMove() {
    $aiColor = $_SESSION['game']['currentPlayer'];
    $possibleMoves = getAllPossibleMoves($aiColor);
    
    if (empty($possibleMoves)) {
        return;
    }
    
    // Filtrera bort drag som sätter egen kung i schack
    $validMoves = [];
    foreach ($possibleMoves as $move) {
        $board = &$_SESSION['game']['board'];
        $piece = $board[$move['from'][0]][$move['from'][1]];
        $capturedPiece = $board[$move['to'][0]][$move['to'][1]];
        
        // Simulera draget
        $board[$move['to'][0]][$move['to'][1]] = $piece;
        $board[$move['from'][0]][$move['from'][1]] = null;
        
        $inCheck = isKingInCheck($aiColor);
        
        // Återställ
        $board[$move['from'][0]][$move['from'][1]] = $piece;
        $board[$move['to'][0]][$move['to'][1]] = $capturedPiece;
        
        if (!$inCheck) {
            $validMoves[] = $move;
        }
    }
    
    if (empty($validMoves)) {
        return; // Schackmatt eller patt
    }
    
    // Utvärdera och välj bästa draget
    $bestScore = -1000;
    $bestMove = null;
    
    foreach ($validMoves as $move) {
        $score = evaluateMove($move);
        // Lägg till lite slumpmässighet
        $score += rand(-5, 5);
        
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMove = $move;
        }
    }
    
    if ($bestMove) {
        // Använd makeMove för att hantera alla regler korrekt
        makeMove($bestMove['from'], $bestMove['to']);
    }
}

$board = $_SESSION['game']['board'];
$currentPlayer = $_SESSION['game']['currentPlayer'];
$playerColor = $_SESSION['playerColor'];
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schack</title>
    <link rel="stylesheet" href="chess.css?v=2">
</head>
<body>
    <div class="container">
        <h1>♔ Schackspel ♚</h1>
        
        <?php if (!isset($_SESSION['game_started'])): ?>
            <div class="color-selection">
                <h2>Välj spelläge</h2>
                
                <h3>🤖 Spela mot Dator</h3>
                <div style="margin-bottom: 30px;">
                    <a href="?reset=1&mode=ai&color=white" class="btn btn-white">Spela Vit mot Dator</a>
                    <a href="?reset=1&mode=ai&color=black" class="btn btn-black">Spela Svart mot Dator</a>
                    <a href="?reset=1&mode=ai&color=random" class="btn btn-random">Slumpa mot Dator</a>
                </div>
                
                <h3>👥 Två Spelare</h3>
                <div>
                    <a href="?reset=1&mode=2player&color=white" class="btn btn-white">Spela Vit</a>
                    <a href="?reset=1&mode=2player&color=black" class="btn btn-black">Spela Svart</a>
                    <a href="?reset=1&mode=2player&color=random" class="btn btn-random">Slumpa</a>
                </div>
            </div>
        <?php else: ?>
        
        <div class="game-info">
            <div class="info-box">
                <strong>Spelläge:</strong> 
                <?= (isset($_SESSION['gameMode']) && $_SESSION['gameMode'] === 'ai') ? '🤖 Mot Dator' : '👥 Två Spelare' ?>
            </div>
            <div class="info-box">
                <strong>Du spelar:</strong> 
                <?= $playerColor === 'white' ? '♔ Vit' : '♚ Svart' ?>
            </div>
            <div class="info-box">
                <strong>Aktuell tur:</strong> 
                <span class="current-turn <?= $currentPlayer ?>">
                    <?= $currentPlayer === 'white' ? '♔ Vit' : '♚ Svart' ?>
                </span>
            </div>
            <?php if (isKingInCheck($currentPlayer)): ?>
            <div class="info-box check-warning">
                <strong>⚠️ SCHACK!</strong>
            </div>
            <?php endif; ?>
            <?php if ($_SESSION['game']['gameOver']): ?>
                <?php if (isset($_SESSION['game']['result']) && $_SESSION['game']['result'] === 'stalemate'): ?>
                <div class="info-box stalemate-warning">
                    <strong>🤝 PATT! Oavgjort</strong>
                </div>
                <?php else: ?>
                <div class="info-box checkmate-warning">
                    <strong>♔ SCHACKMATT! Vinnare: <?= $_SESSION['game']['winner'] === 'white' ? 'VIT' : 'SVART' ?></strong>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <div class="game-container">
            <div class="board-container">
                <div id="chessboard" class="chessboard">
                    <?php for ($row = 0; $row < 8; $row++): ?>
                        <?php for ($col = 0; $col < 8; $col++): ?>
                            <?php
                                $isLight = ($row + $col) % 2 === 0;
                                $piece = $board[$row][$col];
                                $pieceColor = $piece && ctype_upper($piece) ? 'white' : 'black';
                            ?>
                            <div class="square <?= $isLight ? 'light' : 'dark' ?>" 
                                 data-row="<?= $row ?>" 
                                 data-col="<?= $col ?>"
                                 draggable="false">
                                <?php if ($piece): ?>
                                    <div class="piece <?= $pieceColor ?>" 
                                         data-piece="<?= $piece ?>"
                                         draggable="true">
                                        <?= getPieceSymbol($piece) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    <?php endfor; ?>
                </div>
            </div>
            
            <div class="move-history-container">
                <h3>📜 Draghistorik</h3>
                <div class="move-history">
                    <?php 
                    $moveHistory = $_SESSION['game']['moveHistory'] ?? [];
                    if (empty($moveHistory)): 
                    ?>
                        <p class="no-moves">Inga drag ännu...</p>
                    <?php else: ?>
                        <?php 
                        for ($i = 0; $i < count($moveHistory); $i += 2): 
                            $moveNumber = floor($i / 2) + 1;
                            $whiteMove = $moveHistory[$i];
                            $blackMove = isset($moveHistory[$i + 1]) ? $moveHistory[$i + 1] : null;
                        ?>
                            <div class="move-pair">
                                <span class="move-number"><?= $moveNumber ?>.</span>
                                <span class="move white-move">
                                    <span class="piece-icon"><?= getPieceSymbol($whiteMove['piece']) ?></span>
                                    <?= $whiteMove['notation'] ?>
                                </span>
                                <?php if ($blackMove): ?>
                                <span class="move black-move">
                                    <span class="piece-icon"><?= getPieceSymbol($blackMove['piece']) ?></span>
                                    <?= $blackMove['notation'] ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="controls">
            <a href="?reset" class="btn btn-reset">🔄 Nytt Spel</a>
            <a href="?choose" class="btn btn-choose">🎨 Byt Färg</a>
        </div>
        
        <?php endif; ?>
    </div>
    
    <script>
        const playerColor = <?= json_encode($playerColor) ?>;
        const currentPlayer = <?= json_encode($currentPlayer) ?>;
    </script>
    <script src="chess.js?v=2"></script>
</body>
</html>
