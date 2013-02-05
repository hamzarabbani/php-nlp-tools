<?php

namespace NlpTools\Models;

use \NlpTools\FeatureFactories\FeatureFactory;
use \NlpTools\Documents\TrainingSet;

/*
 * Implement a MultinomialNBModel by training on a TrainingSet with a
 * FeatureFactory and additive smoothing.
 */
class FeatureBasedNB implements MultinomialNBModel
{
	// computed prior probabilities
	protected $priors;
	// computed conditional probabilites
	protected $condprob;
	// probability for each unknown word in a class a/(len(terms[class])+a*len(V))
	protected $unknown;
	
	public function __construct() {
		$this->priors = array();
		$this->condprob = array();
		$this->unknown = array();
	}
	
	/*
	 * Return the prior probability of class $class
	 * P(c) as computed by the training data
	 * 
	 * name: getPrior
	 * @param $class
	 * @return float prior probability
	 */
	public function getPrior($class) {
		return $this->priors[$class];
	}
	
	/*
	 * Return the conditional probability of a term for a given class.
	 * 
	 * name: getCondProb
	 * @param $term The term (word, feature id, ...)
	 * @param $class The class
	 * @return float
	 */
	public function getCondProb($term,$class) {
		if (!isset($this->condprob[$term][$class]))
			return $this->unknown[$class];
		else
			return $this->condprob[$term][$class];
	}
	
	/*
	 * Train on the given set and fill the model's variables. Use the
	 * training context provided to update the counts as if the training
	 * set was appended to the previous one that provided the context.
	 * 
	 * It can be used for incremental training. It should *not* be used
	 * with the same training set twice.
	 * 
	 * @param array $train_ctx The previous training context
	 * @param FeatureFactory $ff A feature factory to compute features from a training document
	 * @param TrainingSet The training set
	 * @param $a_smoothing The parameter for additive smoothing. Defaults to add-one smoothing.
	 * @return array Return a training context to be used for further incremental training,
	 *               although this is not necessary since the changes also happen in place
	 * */
	public function train_with_context(array &$train_ctx, FeatureFactory $ff, TrainingSet $tset, $a_smoothing=1) {
		$this->countTrainingSet(
								$ff,
								$tset,
								$train_ctx['termcount_per_class'],
								$train_ctx['termcount'],
								$train_ctx['ndocs_per_class'],
								$train_ctx['voc'],
								$train_ctx['ndocs']
							);
		
		$voccount = count($train_ctx['voc']);
		
		$this->computeProbabilitiesFromCounts(
									$tset->getClassSet(),
									$train_ctx['termcount_per_class'],
									$train_ctx['termcount'],
									$train_ctx['ndocs_per_class'],
									$train_ctx['ndocs'],
									$voccount,
									$a_smoothing
								);
		return $train_ctx;
	}
	
	/*
	 * Train on the given set and fill the models variables
	 * 
	 * priors[c] = NDocs[c]/NDocs
	 * condprob[t][c] = count( t in c) + 1 / sum( count( t' in c ) + 1 , for every t' )
	 * unknown[c] = condbrob['word that doesnt exist in c'][c] ( so that count(t in c)==0 )
	 * 
	 * More information on the algorithm can be found at
	 * http://nlp.stanford.edu/IR-book/html/htmledition/naive-bayes-text-classification-1.html
	 * 
	 * name: train
	 * @param FeatureFactory A feature factory to compute features from a training document
	 * @param TrainingSet The training set
	 * @param $a_smoothing The parameter for additive smoothing. Defaults to add-one smoothing.
	 * @return array Return a training context to be used for incremental training
	 */
	public function train(FeatureFactory $ff, TrainingSet $tset, $a_smoothing=1) {
		$class_set = $tset->getClassSet();
		
		$ctx = array(
			'termcount_per_class'=>array_fill_keys($class_set,0),
			'termcount'=>array_fill_keys($class_set,array()),
			'ndocs_per_class'=>array_fill_keys($class_set,0),
			'voc'=>array(),
			'ndocs'=>0
		);
		
		return $this->train_with_context($ctx,$ff,$tset,$a_smoothing);
	}
	
	/*
	 * Count all the features for each document. All parameters are passed
	 * by reference and they are filled in this function. Useful for not
	 * making copies of big arrays.
	 * 
	 * @param FeatureFactory $ff A feature factory to create the features for each document in the set
	 * @param TrainingSet $tset The training set (collection of labeled documents)
	 * @param array $termcount_per_class The count of occurences of each feature in each class
	 * @param array $termcount The total count of occurences of each term
	 * @param array $ndocs_per_class The total number of documents per class
	 * @param array $voc A set of the found features
	 * @param int $ndocs The number of documents
	 * @return void
	 * */
	protected function countTrainingSet(FeatureFactory $ff, TrainingSet $tset, array &$termcount_per_class, array &$termcount, array &$ndocs_per_class, array &$voc, &$ndocs) {
		foreach ($tset as $tdoc)
		{
			$ndocs++;
			$c = $tdoc->getClass();
			$ndocs_per_class[$c]++;
			$features = $ff->getFeatureArray($c,$tdoc);
			foreach ($features as $f)
			{
				if (!isset($voc[$f]))
					$voc[$f] = 0;
				
				$termcount_per_class[$c]++;
				if (isset($termcount[$c][$f]))
					$termcount[$c][$f]++;
				else
					$termcount[$c][$f] = 1;
			}
		}
	}
	
	/*
	 * Compute the probabilities given the counts of the features in the
	 * training set.
	 * 
	 * @param array $class_set Just the array that contains the classes
	 * @param array $termcount_per_class The count of occurences of each feature in each class
	 * @param array $termcount The total count of occurences of each term
	 * @param array $ndocs_per_class The total number of documents per class
	 * @param int $ndocs The total number of documents
	 * @param int $voccount The total number of features found
	 * @return void
	 * */
	protected function computeProbabilitiesFromCounts(array &$class_set, array &$termcount_per_class, array &$termcount, array &$ndocs_per_class, $ndocs, $voccount, $a_smoothing=1) {
		$denom_smoothing = $a_smoothing*$voccount;
		foreach ($class_set as $class)
		{
			$this->priors[$class] = $ndocs_per_class[$class] / $ndocs;
			foreach ($termcount[$class] as $term=>$count)
			{
				$this->condprob[$term][$class] = ($count + $a_smoothing) / ($termcount_per_class[$class] + $denom_smoothing);
			}
		}
		foreach ($class_set as $class)
		{
			$this->unknown[$class] = $a_smoothing / ($termcount_per_class[$class] + $denom_smoothing);
		}
	}
	
	/*
	 * Just save the probabilities for reuse
	 * */
	public function __sleep() {
		return array('priors','condprob','unknown');
	}
}

?>
