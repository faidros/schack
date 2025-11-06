let selectedSquare = null;
let draggedPiece = null;
let draggedFrom = null;

// Hämta alla rutor och pjäser
const squares = document.querySelectorAll('.square');
const pieces = document.querySelectorAll('.piece');

// Klick-funktionalitet
squares.forEach(square => {
    square.addEventListener('click', handleSquareClick);
});

// Drag-and-drop funktionalitet
pieces.forEach(piece => {
    piece.addEventListener('dragstart', handleDragStart);
    piece.addEventListener('dragend', handleDragEnd);
});

squares.forEach(square => {
    square.addEventListener('dragover', handleDragOver);
    square.addEventListener('drop', handleDrop);
    square.addEventListener('dragleave', handleDragLeave);
    square.addEventListener('dragenter', handleDragEnter);
});

function handleSquareClick(e) {
    const square = e.currentTarget;
    const row = parseInt(square.dataset.row);
    const col = parseInt(square.dataset.col);
    const piece = square.querySelector('.piece');
    
    // Om en ruta redan är vald
    if (selectedSquare) {
        const fromRow = parseInt(selectedSquare.dataset.row);
        const fromCol = parseInt(selectedSquare.dataset.col);
        
        // Om man klickar på samma ruta, avmarkera
        if (selectedSquare === square) {
            selectedSquare.classList.remove('selected');
            clearValidMoves();
            selectedSquare = null;
            return;
        }
        
        // Försök göra draget
        makeMove([fromRow, fromCol], [row, col]);
        
        // Rensa markeringar
        selectedSquare.classList.remove('selected');
        clearValidMoves();
        selectedSquare = null;
    } 
    // Om man klickar på en pjäs
    else if (piece) {
        const pieceType = piece.dataset.piece;
        const isWhitePiece = pieceType === pieceType.toUpperCase();
        const pieceColor = isWhitePiece ? 'white' : 'black';
        
        // Kontrollera att det är rätt spelares tur
        if (pieceColor === currentPlayer) {
            selectedSquare = square;
            square.classList.add('selected');
            highlightValidMoves(row, col, piece);
        }
    }
}

function highlightValidMoves(row, col, piece) {
    // Enkel markering av möjliga drag (kan förbättras)
    squares.forEach(sq => {
        if (!sq.classList.contains('selected')) {
            sq.classList.add('valid-move');
        }
    });
}

function clearValidMoves() {
    squares.forEach(sq => {
        sq.classList.remove('valid-move');
    });
}

function handleDragStart(e) {
    draggedPiece = e.target;
    draggedFrom = e.target.closest('.square');
    
    const pieceType = draggedPiece.dataset.piece;
    const isWhitePiece = pieceType === pieceType.toUpperCase();
    const pieceColor = isWhitePiece ? 'white' : 'black';
    
    // Tillåt bara drag för rätt spelare
    if (pieceColor !== currentPlayer) {
        e.preventDefault();
        return;
    }
    
    setTimeout(() => {
        draggedPiece.classList.add('dragging');
    }, 0);
    
    e.dataTransfer.effectAllowed = 'move';
}

function handleDragEnd(e) {
    draggedPiece.classList.remove('dragging');
    
    // Rensa alla drag-over markeringar
    squares.forEach(sq => {
        sq.classList.remove('drag-over');
    });
}

function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    return false;
}

function handleDragEnter(e) {
    if (e.currentTarget.classList.contains('square')) {
        e.currentTarget.classList.add('drag-over');
    }
}

function handleDragLeave(e) {
    if (e.currentTarget.classList.contains('square')) {
        e.currentTarget.classList.remove('drag-over');
    }
}

function handleDrop(e) {
    e.preventDefault();
    e.stopPropagation();
    
    const targetSquare = e.currentTarget;
    targetSquare.classList.remove('drag-over');
    
    if (!draggedFrom || !draggedPiece) {
        return;
    }
    
    const fromRow = parseInt(draggedFrom.dataset.row);
    const fromCol = parseInt(draggedFrom.dataset.col);
    const toRow = parseInt(targetSquare.dataset.row);
    const toCol = parseInt(targetSquare.dataset.col);
    
    // Gör draget
    makeMove([fromRow, fromCol], [toRow, toCol]);
    
    draggedPiece = null;
    draggedFrom = null;
    
    return false;
}

function makeMove(from, to) {
    const moveData = {
        from: from,
        to: to
    };
    
    // Skicka draget till servern
    const formData = new FormData();
    formData.append('move', JSON.stringify(moveData));
    
    // Visa laddningsindikator om AI-läge
    const infoBoxes = document.querySelectorAll('.info-box');
    const isAIMode = Array.from(infoBoxes).some(box => box.textContent.includes('Mot Dator'));
    
    if (isAIMode) {
        document.body.style.cursor = 'wait';
    }
    
    fetch('index.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(() => {
        // Liten fördröjning för AI:s drag så det känns mer naturligt
        if (isAIMode) {
            setTimeout(() => {
                window.location.reload();
            }, 300);
        } else {
            window.location.reload();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        document.body.style.cursor = 'default';
    });
}

// Förhindra standardbeteende för drag på hela dokumentet
document.addEventListener('dragover', e => {
    e.preventDefault();
});

document.addEventListener('drop', e => {
    e.preventDefault();
});
