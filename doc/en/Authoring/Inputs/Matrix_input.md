# Matrix inputs

STACK provides three ways to let students input a matrix:

1. The matrix input is a fixed grid, one box for each element.
2. The matrix of variable size input is a textarea into which students type in their answer.
3. Students can type in the maxima `matrix` command into another input, e.g. the default algebraic input.

## Matrix input ###

The size of the matrix is inferred from the model answer. STACK then adds an appropriate grid of boxes (of size Box Size) for the student to fill in. This is easier than having students type in [Maxima](../../CAS/Maxima_background.md)'s `matrix` command, but does give the game away about the size of the required matrix.

_The student may not fill in part of a matrix._  If they do so, the remaining entries will be completed with `?` characters which render the attempt invalid. STACK cannot cope with empty boxes here.

We cannot use the `EMPTYANSWER` tag for the teacher's answer with the matrix input, because the size of the matrix is inferred from the model answer.  If a teacher really wants a correct answer to be a completely empty input then they must use a correctly formatted matrix with `null` values

    ta:transpose(matrix([null,null,null]));

The shape of the parentheses surrounding the brackets is taken from the question level options, except matrix inputs cannot display curly brackets `{`.  (If you can create CSS to do this, please contact the developers!)

### Column-vector grid

The extra option `columnvector` keeps the fixed grid but represents the student's response as STACK's
semantic `c(...)` column-vector expression instead of a one-column `matrix(...)`. Use a `c(...)` model
answer, for example

    v: c(1, 2, 3);

and put `columnvector` in the input's extra-options field. Include
`stack_linear_algebra_declare(true)` in the question variables to display `c(...)` expressions as column
vectors. In potential response trees, use `vec_convert(ans1)` when a matrix is required for calculations
or comparison with a matrix model answer.

The rendered grid has `data-stack-input-role="columnvector"`, allowing themes or question CSS to give
column-vector inputs a distinct bracket style without identifying them from their dimensions. The
question-level matrix-parentheses option remains the default visual style.

## Matrix of variable size input ###

The matrix of variable size input is a textarea into which students type in their answer.

Students must separate their matrix elements by spaces, and newline characters.

Input box size is used to determine the starting width of the input.

If you use the `allowempty` option then an empty answer is indicated by the `EMPTYANSWER` tag.  This is a different _type_ than a matrix.  (We could have chosen `matrix()` as the empty matrix, but `EMPTYANSWER` is more in keeping with other inputs.)
